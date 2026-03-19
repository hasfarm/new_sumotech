<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiImageService
{
    private Client $client;
    private ?string $apiKey;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 180,
            'connect_timeout' => 30,
        ]);
        // Sử dụng GEMINI_TTS_API_KEY cho Nano Banana Pro (image generation)
        $this->apiKey = config('services.gemini.tts_api_key') ?: config('services.gemini.api_key');
    }

    /**
     * Generate image using Imagen 3 (Google's text-to-image model)
     * Better at generating images with text than Gemini Flash
     * 
     * @param string $prompt The image generation prompt
     * @param string $outputPath Path to save the generated image
     * @param string $aspectRatio Aspect ratio: "1:1", "16:9", "9:16", "4:3", "3:4"
     * @return array{success: bool, path?: string, error?: string}
     */
    public function generateImage(string $prompt, string $outputPath, string $aspectRatio = '16:9', string $provider = 'gemini'): array
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'flux') {
            return app(FluxImageService::class)->generateImage($prompt, $outputPath, $aspectRatio);
        }

        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error' => 'GEMINI_API_KEY chưa được cấu hình'
            ];
        }

        try {
            Log::info('GeminiImageService: Generating image with Gemini Native Image Generation', [
                'prompt' => substr($prompt, 0, 200) . '...',
                'aspectRatio' => $aspectRatio
            ]);

            // Try Gemini Native Image Generation first (Nano Banana Pro)
            $result = $this->generateWithGeminiNative($prompt, $aspectRatio);

            if (!$result['success']) {
                // Fallback to Imagen 3
                Log::info('GeminiImageService: Gemini Native failed, falling back to Imagen 3', [
                    'reason' => $result['error'] ?? 'unknown'
                ]);
                $result = $this->generateWithImagen3($prompt, $aspectRatio);
            }

            if (!$result['success']) {
                // Last resort: Gemini Flash
                Log::info('GeminiImageService: Imagen 3 failed, falling back to Gemini Flash', [
                    'reason' => $result['error'] ?? 'unknown'
                ]);
                $result = $this->generateWithGeminiFlash($prompt);
            }

            if (!$result['success']) {
                return $result;
            }

            $imageBytes = $result['imageBytes'];

            // Ensure directory exists
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $imageBytes);

            Log::info('GeminiImageService: Image generated successfully', [
                'path' => $outputPath,
                'size' => strlen($imageBytes)
            ]);

            return [
                'success' => true,
                'path' => $outputPath
            ];
        } catch (Exception $e) {
            Log::error('GeminiImageService: Generation failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate vertical short image using the same AI pipeline as thumbnails
     * (Gemini Native / Nano Banana Pro first, then fallback).
     *
     * @param string $conceptPrompt Core concept prompt for the short scene
     * @param string $outputPath Path to save generated image
     * @return array{success: bool, path?: string, error?: string}
     */
    public function generateShortVerticalImage(string $conceptPrompt, string $outputPath, string $provider = 'gemini'): array
    {
        $conceptPrompt = trim($conceptPrompt);
        if ($conceptPrompt === '') {
            return [
                'success' => false,
                'error' => 'Thiếu prompt để tạo ảnh short.'
            ];
        }

        $prompt = "Create a premium vertical 9:16 cinematic image for YouTube Shorts / TikTok.\n\n";
        $prompt .= "Core scene: {$conceptPrompt}\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Vertical composition optimized for mobile viewing (9:16)\n";
        $prompt .= "- Strong subject focus, high contrast, dramatic cinematic lighting\n";
        $prompt .= "- Rich detail, depth, and visual storytelling\n";
        $prompt .= "- Keep center area clear and readable for possible captions\n";
        $prompt .= "- NO text, NO letters, NO watermark, NO logo\n";
        $prompt .= "- Professional quality, click-worthy thumbnail-like visual impact\n";

        Log::info('GeminiImageService: Generating short vertical image with thumbnail-like pipeline', [
            'aspectRatio' => '9:16',
            'prompt_preview' => mb_substr($conceptPrompt, 0, 180),
        ]);

        return $this->generateImage($prompt, $outputPath, '9:16', $provider);
    }

    /**
     * Research book information using Gemini with Google Search grounding
     * and create an optimized image prompt
     * 
     * @param array $bookInfo Book information (title, author, etc.)
     * @return array{success: bool, prompt?: string, research?: string, error?: string}
     */
    public function researchAndCreatePrompt(array $bookInfo): array
    {
        $title = $bookInfo['title'] ?? '';
        $author = $bookInfo['author'] ?? '';
        $category = $bookInfo['category'] ?? '';
        $description = $bookInfo['description'] ?? '';

        try {
            Log::info('GeminiImageService: Researching book info with Google Search grounding', [
                'title' => $title,
                'author' => $author
            ]);

            // Use Gemini with Google Search grounding to research the book
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$this->apiKey}";

            $researchPrompt = "Bạn là chuyên gia về sách và truyện. Hãy tìm hiểu về cuốn sách/truyện này:\n\n";
            $researchPrompt .= "Tiêu đề: {$title}\n";
            if ($author) {
                $researchPrompt .= "Tác giả: {$author}\n";
            }
            if ($category) {
                $researchPrompt .= "Thể loại: {$category}\n";
            }
            if ($description) {
                $researchPrompt .= "Mô tả có sẵn: " . mb_substr($description, 0, 300) . "\n";
            }

            $researchPrompt .= "\nDựa trên thông tin tìm được, hãy mô tả CHI TIẾT một cảnh minh họa đẹp nhất cho truyện này để làm YouTube thumbnail. ";
            $researchPrompt .= "Mô tả phải bao gồm:\n";
            $researchPrompt .= "1. Nhân vật chính (ngoại hình, trang phục, biểu cảm - chọn khoảnh khắc kịch tính nhất)\n";
            $researchPrompt .= "2. Bối cảnh/khung cảnh (địa điểm, thời gian, không khí bí ẩn/hấp dẫn)\n";
            $researchPrompt .= "3. Màu sắc chủ đạo và phong cách nghệ thuật phù hợp\n";
            $researchPrompt .= "4. Chi tiết đặc biệt liên quan đến nội dung truyện\n\n";
            $researchPrompt .= "YÊU CẦU CLICKBAIT CHO YOUTUBE:\n";
            $researchPrompt .= "- Tạo cảm xúc mạnh, gợi sự tò mò\n";
            $researchPrompt .= "- Cảm giác bí ẩn và phiêu lưu\n";
            $researchPrompt .= "- Người xem phải cảm thấy 'Tôi cần biết chuyện gì xảy ra tiếp theo'\n\n";
            $researchPrompt .= "TRÁNH:\n";
            $researchPrompt .= "- Hình ảnh contrast thấp, nhạt nhẽo\n";
            $researchPrompt .= "- Phong cách hoạt hình trẻ con (trừ khi truyện yêu cầu)\n";
            $researchPrompt .= "- Quá nhiều nhân vật trong một cảnh\n";
            $researchPrompt .= "- Bố cục nhàm chán, generic\n\n";
            $researchPrompt .= "Chỉ trả về MÔ TẢ HÌNH ẢNH bằng tiếng Anh, không giải thích thêm. Tối đa 200 từ.";

            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $researchPrompt]
                            ]
                        ]
                    ],
                    'tools' => [
                        [
                            'google_search' => new \stdClass()
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 500
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $candidates = $result['candidates'] ?? [];
            if (empty($candidates)) {
                throw new Exception('Không có kết quả từ Gemini research');
            }

            $parts = $candidates[0]['content']['parts'] ?? [];
            $imagePrompt = '';

            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $imagePrompt .= $part['text'];
                }
            }

            if (empty($imagePrompt)) {
                throw new Exception('Không thể tạo prompt từ research');
            }

            Log::info('GeminiImageService: Research completed', [
                'prompt_length' => strlen($imagePrompt)
            ]);

            return [
                'success' => true,
                'prompt' => trim($imagePrompt)
            ];
        } catch (Exception $e) {
            Log::warning('GeminiImageService: Research failed, using fallback', [
                'error' => $e->getMessage()
            ]);

            // Fallback: create a generic prompt from available info
            $fallbackPrompt = "A beautiful illustration for a Vietnamese story. ";
            if ($category) {
                $fallbackPrompt .= "{$category} genre atmosphere. ";
            }
            $fallbackPrompt .= "Cinematic lighting, detailed artwork, professional quality.";

            return [
                'success' => true,
                'prompt' => $fallbackPrompt,
                'fallback' => true
            ];
        }
    }

    /**
     * Create thumbnail from cover image with text overlay
     * Uses PHP GD to add text on top of the cover image
     * 
     * @param array $bookInfo Book information
     * @param int|null $chapterNumber Chapter number for chapter-specific thumbnails
     * @return array{success: bool, path?: string, url?: string, error?: string}
     */
    public function createThumbnailFromCover(array $bookInfo, ?int $chapterNumber = null): array
    {
        $title = $bookInfo['title'] ?? 'Audiobook';
        $author = $bookInfo['author'] ?? '';
        $bookId = $bookInfo['book_id'] ?? 0;
        $coverImage = $bookInfo['cover_image'] ?? '';
        $category = $bookInfo['category'] ?? '';
        $description = $bookInfo['description'] ?? '';

        if (empty($coverImage)) {
            return [
                'success' => false,
                'error' => 'Không có ảnh bìa'
            ];
        }

        try {
            Log::info('GeminiImageService: Creating 16:9 thumbnail from cover using AI extend', [
                'book_id' => $bookId,
                'cover_image' => $coverImage
            ]);

            // Load the cover image
            $coverPath = storage_path('app/public/' . $coverImage);
            if (!file_exists($coverPath)) {
                throw new Exception('Không tìm thấy file ảnh bìa');
            }

            // Read image and convert to base64
            $imageData = file_get_contents($coverPath);
            $base64Image = base64_encode($imageData);

            // Detect mime type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $imageData);
            finfo_close($finfo);

            // Use Gemini to create background from description + place cover image
            $extendResult = $this->extendImageTo16x9($base64Image, $mimeType, $title, $category, $description);

            if ($extendResult['success']) {
                // Save the extended image
                $outputDir = storage_path('app/public/books/' . $bookId . '/thumbnails');
                if (!is_dir($outputDir)) {
                    mkdir($outputDir, 0755, true);
                }

                $timestamp = time();
                $suffix = $chapterNumber ? "_ch{$chapterNumber}" : '';
                $filename = "bg_cover{$suffix}_{$timestamp}.png";
                $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

                file_put_contents($outputPath, $extendResult['imageBytes']);

                $relativePath = 'books/' . $bookId . '/thumbnails/' . $filename;

                Log::info('GeminiImageService: 16:9 thumbnail created from cover with AI extend', [
                    'path' => $relativePath
                ]);

                return [
                    'success' => true,
                    'path' => $relativePath,
                    'url' => asset('storage/' . $relativePath),
                    'filename' => $filename,
                    'from_cover' => true
                ];
            }

            // Fallback to traditional crop/scale method if AI extend fails
            Log::info('GeminiImageService: AI extend failed, falling back to crop/scale method');
            return $this->createThumbnailFromCoverFallback($bookInfo, $chapterNumber);
        } catch (Exception $e) {
            Log::error('GeminiImageService: Failed to create thumbnail from cover', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Use Gemini 3 Pro Image to extend/outpaint an image to 16:9 aspect ratio
     * Uses gemini-3-pro-image-preview model for best quality
     */
    private function extendImageTo16x9(string $base64Image, string $mimeType, string $title, string $category, string $description = ''): array
    {
        try {
            // Use Gemini 3 Pro Image model
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent?key={$this->apiKey}";

            // Create prompt: keep original cover as-is, generate background from description
            $prompt = "TASK: Create a cinematic 16:9 widescreen YouTube thumbnail (1920x1080) that features this book cover image and a stunning background.\n\n";
            $prompt .= "CRITICAL REQUIREMENTS:\n";
            $prompt .= "1. KEEP THE ORIGINAL BOOK COVER IMAGE EXACTLY AS-IS - do NOT modify, crop, or alter the cover in any way\n";
            $prompt .= "2. Place the book cover prominently in the composition (slightly left-of-center or center, with a slight 3D perspective/tilt for depth)\n";
            $prompt .= "3. Add a subtle shadow or glow effect around the book cover to make it stand out\n";
            $prompt .= "4. GENERATE a beautiful, atmospheric BACKGROUND SCENE inspired by the book's content description (see below)\n";
            $prompt .= "5. The background should visually represent key themes, settings, or moods from the book description\n";
            $prompt .= "6. ABSOLUTELY NO TEXT, LETTERS, WORDS, or TYPOGRAPHY anywhere in the image\n";
            $prompt .= "7. Leave the lower 15-20% slightly darker/simpler for future text overlay\n";
            $prompt .= "8. The overall composition should look like a premium YouTube audiobook thumbnail\n\n";

            if ($description) {
                // Truncate description to avoid token limits
                $descTruncated = mb_substr($description, 0, 800);
                $prompt .= "BOOK DESCRIPTION (use this to create the background scene):\n";
                $prompt .= "\"{$descTruncated}\"\n\n";
                $prompt .= "Based on this description, create a background that captures the essence, setting, mood and key visual elements of the story.\n\n";
            }

            if ($category) {
                $prompt .= "GENRE: '{$category}' - incorporate thematically appropriate visual elements in the background.\n\n";
            }

            $prompt .= "STYLE: Professional, cinematic, immersive. The background should draw viewers in and convey the book's atmosphere.\n";
            $prompt .= "OUTPUT: A stunning 16:9 widescreen image with the original book cover placed on a descriptive, thematic background.";

            Log::info('GeminiImageService: Extending image with Nano Banana Pro', [
                'mimeType' => $mimeType,
                'category' => $category
            ]);

            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => $mimeType,
                                        'data' => $base64Image
                                    ]
                                ],
                                [
                                    'text' => $prompt
                                ]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseModalities' => ['TEXT', 'IMAGE'],
                        'responseMimeType' => 'text/plain'
                    ]
                ],
                'timeout' => 180
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            // Extract image from response (same logic as generateWithGeminiNative)
            $candidates = $result['candidates'] ?? [];
            if (empty($candidates)) {
                throw new Exception('Không có kết quả từ Nano Banana Pro');
            }

            $parts = $candidates[0]['content']['parts'] ?? [];
            $imageData = null;

            foreach ($parts as $part) {
                if (isset($part['inlineData']['data'])) {
                    $imageData = $part['inlineData']['data'];
                    break;
                }
                // Also check for inlineData without nested data (alternative format)
                if (isset($part['inlineData']) && is_string($part['inlineData'])) {
                    $imageData = $part['inlineData'];
                    break;
                }
            }

            if (!$imageData) {
                Log::warning('GeminiImageService: No image in Nano Banana Pro response', [
                    'response_keys' => array_keys($result),
                    'candidates_count' => count($candidates),
                    'parts_count' => count($parts)
                ]);
                throw new Exception('Nano Banana Pro không trả về hình ảnh');
            }

            $imageBytes = base64_decode($imageData);

            Log::info('GeminiImageService: Successfully extended image to 16:9 with Nano Banana Pro', [
                'output_size' => strlen($imageBytes)
            ]);

            return [
                'success' => true,
                'imageBytes' => $imageBytes
            ];
        } catch (Exception $e) {
            Log::error('GeminiImageService: Nano Banana Pro image extend failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fallback method: Create thumbnail from cover using crop/scale
     */
    private function createThumbnailFromCoverFallback(array $bookInfo, ?int $chapterNumber = null): array
    {
        $bookId = $bookInfo['book_id'] ?? 0;
        $coverImage = $bookInfo['cover_image'] ?? '';

        try {
            $coverPath = storage_path('app/public/' . $coverImage);

            // Get image info
            $imageInfo = getimagesize($coverPath);
            $mime = $imageInfo['mime'] ?? '';

            // Create image resource based on type
            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $sourceImage = imagecreatefromjpeg($coverPath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($coverPath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($coverPath);
                    break;
                default:
                    throw new Exception('Định dạng ảnh không được hỗ trợ: ' . $mime);
            }

            if (!$sourceImage) {
                throw new Exception('Không thể đọc file ảnh');
            }

            // Target dimensions (YouTube thumbnail: 1280x720)
            $targetWidth = 1280;
            $targetHeight = 720;

            // Get source dimensions
            $sourceWidth = imagesx($sourceImage);
            $sourceHeight = imagesy($sourceImage);

            // Create a blurred background first (16:9)
            $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

            // Scale source to cover entire background (will be blurred)
            $bgScale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
            $bgWidth = (int)($sourceWidth * $bgScale);
            $bgHeight = (int)($sourceHeight * $bgScale);
            $bgOffsetX = (int)(($targetWidth - $bgWidth) / 2);
            $bgOffsetY = (int)(($targetHeight - $bgHeight) / 2);

            imagecopyresampled($thumbnail, $sourceImage, $bgOffsetX, $bgOffsetY, 0, 0, $bgWidth, $bgHeight, $sourceWidth, $sourceHeight);

            // Apply blur effect to background (simulate blur by scaling down and up)
            $smallWidth = (int)($targetWidth / 10);
            $smallHeight = (int)($targetHeight / 10);
            $small = imagecreatetruecolor($smallWidth, $smallHeight);
            imagecopyresampled($small, $thumbnail, 0, 0, 0, 0, $smallWidth, $smallHeight, $targetWidth, $targetHeight);
            imagecopyresampled($thumbnail, $small, 0, 0, 0, 0, $targetWidth, $targetHeight, $smallWidth, $smallHeight);
            imagedestroy($small);

            // Add dark overlay for better contrast
            $overlay = imagecolorallocatealpha($thumbnail, 0, 0, 0, 60);
            imagefilledrectangle($thumbnail, 0, 0, $targetWidth, $targetHeight, $overlay);

            // Now place the original cover in the center at a nice size
            $coverMaxHeight = (int)($targetHeight * 0.85);
            $coverScale = $coverMaxHeight / $sourceHeight;
            $coverNewWidth = (int)($sourceWidth * $coverScale);
            $coverNewHeight = (int)($sourceHeight * $coverScale);
            $coverX = (int)(($targetWidth - $coverNewWidth) / 2);
            $coverY = (int)(($targetHeight - $coverNewHeight) / 2);

            imagecopyresampled($thumbnail, $sourceImage, $coverX, $coverY, 0, 0, $coverNewWidth, $coverNewHeight, $sourceWidth, $sourceHeight);

            // Save the thumbnail
            $outputDir = storage_path('app/public/books/' . $bookId . '/thumbnails');
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $timestamp = time();
            $suffix = $chapterNumber ? "_ch{$chapterNumber}" : '';
            $filename = "bg_cover_blur{$suffix}_{$timestamp}.png";
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

            imagepng($thumbnail, $outputPath, 9);

            // Clean up
            imagedestroy($sourceImage);
            imagedestroy($thumbnail);

            $relativePath = 'books/' . $bookId . '/thumbnails/' . $filename;

            Log::info('GeminiImageService: Fallback thumbnail created with blur effect', [
                'path' => $relativePath
            ]);

            return [
                'success' => true,
                'path' => $relativePath,
                'url' => asset('storage/' . $relativePath),
                'filename' => $filename,
                'from_cover' => true,
                'method' => 'blur_fallback'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Add gradient overlay to image for text readability
     */
    private function addGradientOverlay($image, int $width, int $height): void
    {
        // Add dark gradient from bottom (for text area)
        for ($y = $height - 300; $y < $height; $y++) {
            $alpha = (int)(($y - ($height - 300)) / 300 * 80); // 0 to 80
            $color = imagecolorallocatealpha($image, 0, 0, 0, 127 - $alpha);
            imageline($image, 0, $y, $width, $y, $color);
        }

        // Add subtle dark overlay at top for book type label
        for ($y = 0; $y < 80; $y++) {
            $alpha = (int)((80 - $y) / 80 * 50);
            $color = imagecolorallocatealpha($image, 0, 0, 0, 127 - $alpha);
            imageline($image, 0, $y, $width, $y, $color);
        }
    }

    /**
     * Add text overlays to thumbnail
     */
    private function addTextToThumbnail($image, int $width, int $height, array $textInfo): void
    {
        // Use a built-in font or system font
        // For Vietnamese text, we need a Unicode-compatible font
        $fontPath = storage_path('app/fonts/Roboto-Bold.ttf');
        $fontPathRegular = storage_path('app/fonts/Roboto-Regular.ttf');

        // Fallback to built-in font if custom font not available
        $useBuiltinFont = !file_exists($fontPath);

        // Colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $yellow = imagecolorallocate($image, 255, 220, 50);
        $lightGray = imagecolorallocate($image, 200, 200, 200);

        // Book type label (top left)
        if (!empty($textInfo['book_type'])) {
            if ($useBuiltinFont) {
                imagestring($image, 5, 30, 20, $textInfo['book_type'], $yellow);
            } else {
                imagettftext($image, 24, 0, 30, 50, $yellow, $fontPath, $textInfo['book_type']);
            }
        }

        // Chapter number (top right) if specified
        if (!empty($textInfo['chapter_number'])) {
            $chapterText = "Chương " . $textInfo['chapter_number'];
            if ($useBuiltinFont) {
                $textWidth = strlen($chapterText) * 9;
                imagestring($image, 5, $width - $textWidth - 30, 20, $chapterText, $yellow);
            } else {
                $bbox = imagettfbbox(28, 0, $fontPath, $chapterText);
                $textWidth = abs($bbox[4] - $bbox[0]);
                imagettftext($image, 28, 0, $width - $textWidth - 30, 50, $yellow, $fontPath, $chapterText);
            }
        }

        // Title (center bottom area)
        if (!empty($textInfo['title'])) {
            $titleText = mb_strimwidth($textInfo['title'], 0, 50, '...');
            if ($useBuiltinFont) {
                $textWidth = strlen($titleText) * 9;
                imagestring($image, 5, ($width - $textWidth) / 2, $height - 120, $titleText, $white);
            } else {
                // Calculate centered position
                $fontSize = 42;
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $titleText);
                $textWidth = abs($bbox[4] - $bbox[0]);
                $x = ($width - $textWidth) / 2;
                imagettftext($image, $fontSize, 0, $x, $height - 100, $white, $fontPath, $titleText);
            }
        }

        // Author (below title)
        if (!empty($textInfo['author'])) {
            $authorText = "Tác giả: " . $textInfo['author'];
            if ($useBuiltinFont) {
                $textWidth = strlen($authorText) * 7;
                imagestring($image, 4, ($width - $textWidth) / 2, $height - 80, $authorText, $lightGray);
            } else {
                $fontSize = 24;
                $fontToUse = file_exists($fontPathRegular) ? $fontPathRegular : $fontPath;
                $bbox = imagettfbbox($fontSize, 0, $fontToUse, $authorText);
                $textWidth = abs($bbox[4] - $bbox[0]);
                $x = ($width - $textWidth) / 2;
                imagettftext($image, $fontSize, 0, $x, $height - 55, $lightGray, $fontToUse, $authorText);
            }
        }

        // Channel name (bottom right corner)
        if (!empty($textInfo['channel_name'])) {
            if ($useBuiltinFont) {
                $textWidth = strlen($textInfo['channel_name']) * 6;
                imagestring($image, 3, $width - $textWidth - 20, $height - 30, $textInfo['channel_name'], $lightGray);
            } else {
                $fontSize = 16;
                $fontToUse = file_exists($fontPathRegular) ? $fontPathRegular : $fontPath;
                $bbox = imagettfbbox($fontSize, 0, $fontToUse, $textInfo['channel_name']);
                $textWidth = abs($bbox[4] - $bbox[0]);
                imagettftext($image, $fontSize, 0, $width - $textWidth - 20, $height - 20, $lightGray, $fontToUse, $textInfo['channel_name']);
            }
        }
    }

    /**
     * Generate image using Gemini 3 Pro Image
     * Uses gemini-3-pro-image-preview model for highest quality
     */
    private function generateWithGeminiNative(string $prompt, string $aspectRatio = '16:9'): array
    {
        try {
            // Gemini 3 Pro Image model
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent?key={$this->apiKey}";

            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseModalities' => ['TEXT', 'IMAGE'],
                        'responseMimeType' => 'text/plain'
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $candidates = $result['candidates'] ?? [];
            if (empty($candidates)) {
                throw new Exception('Không có kết quả từ Gemini Native');
            }

            $parts = $candidates[0]['content']['parts'] ?? [];
            $imageData = null;

            foreach ($parts as $part) {
                if (isset($part['inlineData'])) {
                    $imageData = $part['inlineData']['data'];
                    break;
                }
            }

            if (!$imageData) {
                throw new Exception('Gemini Native không trả về hình ảnh');
            }

            Log::info('GeminiImageService: Gemini Native Image Generation succeeded');

            return [
                'success' => true,
                'imageBytes' => base64_decode($imageData)
            ];
        } catch (Exception $e) {
            Log::warning('GeminiImageService: Gemini Native failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate image using Imagen 3 API
     */
    private function generateWithImagen3(string $prompt, string $aspectRatio = '16:9'): array
    {
        try {
            // Imagen 3 API endpoint
            $url = "https://generativelanguage.googleapis.com/v1beta/models/imagen-3.0-generate-002:predict?key={$this->apiKey}";

            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'instances' => [
                        ['prompt' => $prompt]
                    ],
                    'parameters' => [
                        'sampleCount' => 1,
                        'aspectRatio' => $aspectRatio,
                        'personGeneration' => 'allow_adult',
                        'safetySetting' => 'block_few'
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $predictions = $result['predictions'] ?? [];
            if (empty($predictions) || !isset($predictions[0]['bytesBase64Encoded'])) {
                throw new Exception('Imagen 3 không trả về hình ảnh');
            }

            $imageData = $predictions[0]['bytesBase64Encoded'];
            $imageBytes = base64_decode($imageData);

            return [
                'success' => true,
                'imageBytes' => $imageBytes
            ];
        } catch (Exception $e) {
            Log::warning('GeminiImageService: Imagen 3 failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate image using Gemini 2.0 Flash (last resort fallback)
     */
    private function generateWithGeminiFlash(string $prompt): array
    {
        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent?key={$this->apiKey}";

            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseModalities' => ['TEXT', 'IMAGE'],
                        'responseMimeType' => 'text/plain'
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $candidates = $result['candidates'] ?? [];
            if (empty($candidates)) {
                throw new Exception('Không có kết quả từ Gemini');
            }

            $parts = $candidates[0]['content']['parts'] ?? [];
            $imageData = null;

            foreach ($parts as $part) {
                if (isset($part['inlineData'])) {
                    $imageData = $part['inlineData']['data'];
                    break;
                }
            }

            if (!$imageData) {
                throw new Exception('Gemini Flash không trả về hình ảnh');
            }

            return [
                'success' => true,
                'imageBytes' => base64_decode($imageData)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate YouTube thumbnail background image ONLY (no text)
     * Step 1: Creates background image that can later have text added via FFmpeg
     * 
     * @param array $bookInfo Book information
     * @param string $style Visual style
     * @param int|null $chapterNumber Chapter number for chapter-specific thumbnails
     * @param string|null $customPrompt Custom scene description
     * @return array
     */
    /**
     * Build prompt for thumbnail WITHOUT text (background only)
     */
    public function buildThumbnailPrompt(array $bookInfo, string $style = 'cinematic', ?string $customPrompt = null): string
    {
        $category = $bookInfo['category'] ?? '';
        $preferPortrait = (bool)($bookInfo['prefer_portrait'] ?? true);

        $stylePrompts = [
            'cinematic' => 'cinematic movie scene, dramatic lighting, dark moody atmospheric, film noir aesthetic',
            'anime' => 'anime artwork style, vibrant colors, Japanese animation aesthetic, detailed anime scene',
            'illustration' => 'digital illustration artwork, artistic painting, detailed fantasy artwork',
            'realistic' => 'photorealistic scene, high detail photography, dramatic studio lighting',
            'vintage' => 'vintage retro artwork, classic painting style, warm sepia tones, old poster art',
            'fantasy' => 'epic fantasy landscape, magical atmosphere, detailed fantasy world painting',
            'mystery' => 'dark mysterious scene, noir atmosphere, shadowy dramatic lighting',
            'romance' => 'romantic dreamy scene, soft lighting, elegant aesthetic, pastel colors',
            'modern' => 'modern abstract art, minimalist design, clean geometric shapes',
            'gradient' => 'beautiful abstract gradient, colorful smooth background, modern art'
        ];

        $styleDescription = $stylePrompts[$style] ?? $stylePrompts['cinematic'];

        $prompt = "Generate a pure visual artwork image with ABSOLUTELY NO TEXT, NO WORDS, NO LETTERS, NO TYPOGRAPHY.\n\n";
        $prompt .= "Art Style: {$styleDescription}\n\n";

        if ($customPrompt) {
            $cleanPrompt = preg_replace('/["\'].*?["\']/', '', $customPrompt);
            $prompt .= "Visual Scene: {$cleanPrompt}\n\n";
        }

        if ($category) {
            $prompt .= "Visual Theme: {$category} genre atmosphere\n\n";
        }

        $prompt .= "CRITICAL REQUIREMENTS - MUST FOLLOW:\n";
        $prompt .= "1. ZERO TEXT - No letters, no words, no numbers, no characters, no writing of any kind\n";
        $prompt .= "2. ZERO TYPOGRAPHY - No titles, no captions, no labels, no watermarks\n";
        $prompt .= "3. PURE VISUAL ART ONLY - Only scenery, characters, objects, lighting, atmosphere\n";
        $prompt .= "4. This image will have text added externally later - keep bottom area simple/darker\n";
        $prompt .= "5. 16:9 aspect ratio, high quality, visually striking\n\n";

        $prompt .= "Create an emotionally engaging visual:\n";
        $prompt .= "- Strong visual focal point\n";
        $prompt .= "- Dramatic or mysterious atmosphere\n";
        $prompt .= "- Rich colors and lighting\n";
        $prompt .= "- Professional composition\n\n";

        if ($preferPortrait) {
            $prompt .= "PRIMARY SUBJECT (Very Important):\n";
            $prompt .= "- The MAIN CHARACTER/protagonist as a HUMAN portrait\n";
            $prompt .= "- Head-and-shoulders framing, FACE FRONT, eye contact with camera\n";
            $prompt .= "- Camera angle straight-on (no tilt), eye-level, front-facing\n";
            $prompt .= "- Subject centered or on the LEFT THIRD; keep RIGHT side cleaner for potential text overlay\n";
            $prompt .= "- High-detail skin, eyes, and facial expression; cinematic rim/back light\n\n";

            $prompt .= "NEGATIVE CONSTRAINTS for subject (must avoid):\n";
            $prompt .= "- No profile/side view, no back view, no looking away\n";
            $prompt .= "- No masks/helmets/covered faces, no motion blur on face\n";
            $prompt .= "- No groups/crowds; exactly ONE person as the focal subject\n\n";
        }

        $prompt .= "STRICTLY FORBIDDEN - WILL BE REJECTED IF PRESENT:\n";
        $prompt .= "- ANY text in ANY language (English, Vietnamese, Chinese, Japanese, etc.)\n";
        $prompt .= "- ANY letters or alphabets\n";
        $prompt .= "- ANY numbers or symbols that look like text\n";
        $prompt .= "- ANY UI elements, buttons, or labels\n";
        $prompt .= "- ANY watermarks or signatures\n";
        $prompt .= "- ANY book covers, posters, or signs with text";

        return $prompt;
    }

    /**
     * Build prompt for thumbnail WITH AI-rendered text
     */
    public function buildThumbnailWithTextPrompt(array $bookInfo, string $style = 'cinematic', ?int $chapterNumber = null, ?string $customPrompt = null): string
    {
        $title = $bookInfo['title'] ?? 'Audiobook';
        $author = $bookInfo['author'] ?? '';
        $category = $bookInfo['category'] ?? '';
        $preferPortrait = (bool)($bookInfo['prefer_portrait'] ?? true);

        $stylePrompts = [
            'cinematic' => 'cinematic movie poster style with dramatic lighting',
            'anime' => 'anime style with vibrant colors',
            'illustration' => 'digital illustration artistic style',
            'realistic' => 'photorealistic professional photography style',
            'vintage' => 'vintage retro classic design',
            'fantasy' => 'epic fantasy art magical atmosphere',
            'mystery' => 'dark mysterious noir style',
            'romance' => 'romantic soft lighting elegant design',
            'modern' => 'modern minimalist clean design',
            'gradient' => 'beautiful gradient background modern design'
        ];

        $styleDescription = $stylePrompts[$style] ?? $stylePrompts['cinematic'];

        $prompt = "Generate a YouTube thumbnail image (16:9 aspect ratio) that contains VISIBLE TEXT.\n\n";
        $prompt .= "CRITICAL: This image MUST display the following text CLEARLY AND LEGIBLY:\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "MAIN TITLE (large, bold, centered): {$title}\n";
        if ($author) {
            $prompt .= "SUBTITLE (smaller, below title): {$author}\n";
        }
        if ($chapterNumber) {
            $prompt .= "BADGE/LABEL: Chương {$chapterNumber}\n";
        }
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $prompt .= "DESIGN STYLE: {$styleDescription}\n\n";

        if ($customPrompt) {
            $prompt .= "BACKGROUND SCENE: {$customPrompt}\n\n";
        } elseif ($category) {
            $prompt .= "THEME/GENRE: {$category}\n\n";
        }

        if ($preferPortrait) {
            $prompt .= "PRIMARY VISUAL SUBJECT (Very Important):\n";
            $prompt .= "• The MAIN CHARACTER/protagonist as a HUMAN portrait, head-and-shoulders, FRONT-FACING, eye contact\n";
            $prompt .= "• Place character on the LEFT THIRD; reserve CLEAN SPACE on the RIGHT for the title text\n";
            $prompt .= "• Eye-level straight camera, high-detail face with dramatic lighting\n\n";

            $prompt .= "NEGATIVE CONSTRAINTS (avoid):\n";
            $prompt .= "• No side view/profile/back view; no covered/obscured faces; no groups\n\n";
        }

        $prompt .= "TEXT RENDERING REQUIREMENTS:\n";
        $prompt .= "• The title \"{$title}\" MUST be rendered as ACTUAL TEXT in the image\n";
        $prompt .= "• Text must be WHITE or YELLOW with BLACK OUTLINE/SHADOW for readability\n";
        $prompt .= "• Font style: Bold, blocky, movie-poster style\n";
        $prompt .= "• Text position: Right of the image (use the reserved clean space)\n";
        $prompt .= "• Text must be perfectly spelled - copy exactly as provided\n";
        $prompt .= "• Do NOT blur, distort, or partially hide the text\n";
        $prompt .= "• Make the text the FOCAL POINT of the thumbnail\n\n";
        $prompt .= "OUTPUT: A professional YouTube thumbnail with the exact text \"{$title}\" prominently displayed and readable.";

        return $prompt;
    }

    public function generateThumbnail(array $bookInfo, string $style = 'cinematic', ?int $chapterNumber = null, ?string $customPrompt = null, ?string $overridePrompt = null, string $provider = 'gemini'): array
    {
        $bookId = $bookInfo['book_id'] ?? 0;

        $prompt = $overridePrompt ?: $this->buildThumbnailPrompt($bookInfo, $style, $customPrompt);

        // Generate output path for the background image
        $outputDir = storage_path('app/public/books/' . $bookId . '/thumbnails');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $timestamp = time();
        $suffix = $chapterNumber ? "_ch{$chapterNumber}" : '';
        $bgFilename = "bg_{$style}{$suffix}_{$timestamp}.png";
        $bgOutputPath = $outputDir . DIRECTORY_SEPARATOR . $bgFilename;

        // Generate background image with Gemini
        $result = $this->generateImage($prompt, $bgOutputPath, '16:9', $provider);

        if (!$result['success']) {
            return $result;
        }

        // Return background image path (no text overlay)
        $relativePath = 'books/' . $bookId . '/thumbnails/' . $bgFilename;
        return [
            'success' => true,
            'path' => $relativePath,
            'url' => asset('storage/' . $relativePath),
            'prompt' => $prompt,
            'filename' => $bgFilename
        ];
    }

    /**
     * Generate YouTube thumbnail with text overlay
     * Creates a thumbnail with book title, author, and chapter info
     * 
     * @param array $bookInfo Book information
     * @param string $style Visual style
     * @param int|null $chapterNumber Chapter number for chapter-specific thumbnails
     * @return array
     */
    public function generateThumbnailWithText(array $bookInfo, string $style = 'cinematic', ?int $chapterNumber = null, ?string $customPrompt = null, ?string $overridePrompt = null, string $provider = 'gemini'): array
    {
        return $this->generateThumbnailWithAIText($bookInfo, $style, $chapterNumber, $customPrompt, $overridePrompt, $provider);
    }

    public function generateThumbnailWithAIText(array $bookInfo, string $style = 'cinematic', ?int $chapterNumber = null, ?string $customPrompt = null, ?string $overridePrompt = null, string $provider = 'gemini'): array
    {
        $title = $bookInfo['title'] ?? 'Audiobook';
        $author = $bookInfo['author'] ?? '';
        $bookId = $bookInfo['book_id'] ?? 0;

        Log::info('GeminiImageService: generateThumbnailWithAIText called', [
            'title' => $title,
            'author' => $author,
            'style' => $style,
            'chapterNumber' => $chapterNumber,
        ]);

        $prompt = $overridePrompt ?: $this->buildThumbnailWithTextPrompt($bookInfo, $style, $chapterNumber, $customPrompt);

        // Generate output path
        $outputDir = storage_path('app/public/books/' . $bookId . '/thumbnails');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $timestamp = time();
        $suffix = $chapterNumber ? "_ch{$chapterNumber}" : '';
        $filename = "thumb_ai_{$style}{$suffix}_{$timestamp}.png";
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

        Log::info('GeminiImageService: Generating thumbnail with AI text', [
            'prompt_length' => strlen($prompt),
            'output_path' => $outputPath
        ]);

        // Generate image with Gemini (text included by AI)
        $result = $this->generateImage($prompt, $outputPath, '16:9', $provider);

        if (!$result['success']) {
            Log::error('GeminiImageService: Failed to generate thumbnail with AI text', [
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            return $result;
        }

        Log::info('GeminiImageService: Successfully generated thumbnail with AI text', [
            'filename' => $filename,
            'title' => $title
        ]);

        $relativePath = 'books/' . $bookId . '/thumbnails/' . $filename;
        return [
            'success' => true,
            'path' => $relativePath,
            'url' => asset('storage/' . $relativePath),
            'prompt' => $prompt,
            'filename' => $filename,
            'ai_text' => true,
            'text_elements' => array_filter([
                'title' => $title,
                'author' => $author ?: null,
                'chapter' => $chapterNumber ? "Chương {$chapterNumber}" : null
            ])
        ];
    }

    /**
     * Generate YouTube thumbnail with FFmpeg text overlay (legacy method)
     * Creates a background image then adds text with FFmpeg
     * 
     * @param array $bookInfo Book information
     * @param string $style Visual style
     * @param int|null $chapterNumber Chapter number for chapter-specific thumbnails
     * @param string|null $customPrompt Custom scene description
     * @return array
     */
    public function generateThumbnailWithFFmpegText(array $bookInfo, string $style = 'cinematic', ?int $chapterNumber = null, ?string $customPrompt = null, string $provider = 'gemini'): array
    {
        $title = $bookInfo['title'] ?? 'Audiobook';
        $author = $bookInfo['author'] ?? '';
        $category = $bookInfo['category'] ?? '';
        $bookType = $bookInfo['book_type'] ?? '';
        $bookId = $bookInfo['book_id'] ?? 0;
        $description = $bookInfo['description'] ?? '';
        $textStyling = $bookInfo['text_styling'] ?? [];

        // Style descriptions for background image ONLY (no text)
        $stylePrompts = [
            'cinematic' => 'cinematic movie poster style, dramatic lighting, professional design, dark moody atmospheric background',
            'anime' => 'anime style, vibrant colors, Japanese animation aesthetic, stylized scene',
            'illustration' => 'digital illustration, artistic, detailed artwork, storybook style',
            'realistic' => 'photorealistic, high detail, professional photography, studio lighting',
            'vintage' => 'vintage retro style, classic design, warm sepia tones',
            'fantasy' => 'epic fantasy art, magical atmosphere, detailed fantasy illustration',
            'mystery' => 'dark mysterious noir style, suspenseful, shadowy atmosphere',
            'romance' => 'romantic soft lighting, elegant design, dreamy aesthetic',
            'modern' => 'modern minimalist design, clean background, professional',
            'gradient' => 'beautiful gradient background, modern design, clean layout'
        ];

        $styleDescription = $stylePrompts[$style] ?? $stylePrompts['cinematic'];

        // Determine text position for prompt hint
        $textPosition = $textStyling['position'] ?? 'bottom';
        $clearArea = match ($textPosition) {
            'top' => 'top 25%',
            'center' => 'center area',
            'bottom' => 'bottom 25%',
            default => 'bottom 25%'
        };

        // Build prompt for BACKGROUND IMAGE ONLY - NO TEXT
        $prompt = "Create a stunning YouTube thumbnail BACKGROUND image.\n\n";
        $prompt .= "Style: {$styleDescription}\n";

        // Add custom prompt as scene description if provided
        if ($customPrompt) {
            $prompt .= "Scene/Background: {$customPrompt}\n";
        } elseif ($description) {
            // Use book description to generate relevant scene
            $shortDesc = mb_substr($description, 0, 400);
            $prompt .= "Theme based on: {$shortDesc}\n";
        }

        if ($category) {
            $prompt .= "Genre: {$category}\n";
        }

        $prompt .= "\nIMPORTANT REQUIREMENTS:\n";
        $prompt .= "- DO NOT include ANY text, letters, words, or typography in the image\n";
        $prompt .= "- This is ONLY the background/scene image\n";
        $prompt .= "- Text will be added separately using FFmpeg\n";
        $prompt .= "- Leave the {$clearArea} of the image relatively simple/dark for text overlay\n";
        $prompt .= "- 16:9 aspect ratio for YouTube thumbnail\n";
        $prompt .= "- High quality, visually striking composition\n\n";

        // Clickability guidelines
        $prompt .= "Make the image emotionally engaging:\n";
        $prompt .= "- Strong visual hook that captures attention\n";
        $prompt .= "- Sense of mystery, adventure, or drama\n";
        $prompt .= "- High visual impact and curiosity-inducing elements\n\n";

        // Negative prompt
        $prompt .= "AVOID (Negative Prompt):\n";
        $prompt .= "- Any text, letters, words, numbers, or typography\n";
        $prompt .= "- Watermarks, logos, or UI elements\n";
        $prompt .= "- Low contrast images\n";
        $prompt .= "- Flat illustration style\n";
        $prompt .= "- Too cluttered composition\n";
        $prompt .= "- Generic or boring backgrounds";

        // Generate output path for the background image
        $outputDir = storage_path('app/public/books/' . $bookId . '/thumbnails');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $timestamp = time();
        $suffix = $chapterNumber ? "_ch{$chapterNumber}" : '';
        $bgFilename = "bg_{$style}{$suffix}_{$timestamp}.png";
        $bgOutputPath = $outputDir . DIRECTORY_SEPARATOR . $bgFilename;

        // Generate background image with Gemini
        $result = $this->generateImage($prompt, $bgOutputPath, '16:9', $provider);

        if (!$result['success']) {
            return $result;
        }

        // Now add text overlay using FFmpeg
        $finalFilename = "thumb_{$style}{$suffix}_{$timestamp}.png";
        $finalOutputPath = $outputDir . DIRECTORY_SEPARATOR . $finalFilename;

        // Build text elements for FFmpeg overlay
        $textElements = [];
        $textElements['title'] = $title;
        if ($author) {
            $textElements['author'] = $author;
        }
        if ($chapterNumber) {
            $textElements['chapter'] = "Chương {$chapterNumber}";
        }

        // Add text overlay with FFmpeg (with styling options)
        $ffmpegResult = $this->addTextOverlayWithFFmpeg($bgOutputPath, $finalOutputPath, $textElements, $textStyling);

        if ($ffmpegResult['success']) {
            // Optionally delete the background-only image
            // unlink($bgOutputPath);

            $relativePath = 'books/' . $bookId . '/thumbnails/' . $finalFilename;
            return [
                'success' => true,
                'path' => $relativePath,
                'url' => asset('storage/' . $relativePath),
                'prompt' => $prompt,
                'text_elements' => array_values($textElements)
            ];
        } else {
            // FFmpeg failed, return the background image as fallback
            $relativePath = 'books/' . $bookId . '/thumbnails/' . $bgFilename;
            return [
                'success' => true,
                'path' => $relativePath,
                'url' => asset('storage/' . $relativePath),
                'prompt' => $prompt,
                'text_elements' => [],
                'warning' => 'Text overlay failed: ' . ($ffmpegResult['error'] ?? 'Unknown error')
            ];
        }
    }

    /**
     * Add text overlay to image using FFmpeg with styling options
     */
    private function addTextOverlayWithFFmpeg(string $inputPath, string $outputPath, array $textElements, array $styling = []): array
    {
        $ffmpegPath = config('services.ffmpeg.path', 'ffmpeg');

        // Extract styling options with defaults
        $position = $styling['position'] ?? 'bottom';
        $textColor = $styling['textColor'] ?? '#ffffff';
        $borderColor = $styling['borderColor'] ?? '#000000';
        $borderWidth = (int)($styling['borderWidth'] ?? 4);
        $bgStyle = $styling['bgStyle'] ?? 'none';
        $bgColor = $styling['bgColor'] ?? '#000000';
        $bgOpacity = (int)($styling['bgOpacity'] ?? 70);
        $titleFontSize = (int)($styling['titleFontSize'] ?? 60);

        // Convert hex colors to FFmpeg format (remove #)
        $textColorFfmpeg = ltrim($textColor, '#');
        $borderColorFfmpeg = ltrim($borderColor, '#');
        $bgColorFfmpeg = ltrim($bgColor, '#');

        // Find font that supports Vietnamese
        $fontPath = $this->findVietnameseFont();
        $fontFile = $fontPath ? "fontfile='" . str_replace(['\\', ':'], ['/', '\\:'], $fontPath) . "':" : '';

        $filters = [];

        // Add background overlay if needed
        if ($bgStyle !== 'none') {
            $bgHeight = 180; // Height of text area
            $bgOpacityHex = str_pad(dechex((int)($bgOpacity * 2.55)), 2, '0', STR_PAD_LEFT);

            switch ($position) {
                case 'top':
                    $bgY = 0;
                    break;
                case 'center':
                    $bgY = '(h-' . $bgHeight . ')/2';
                    break;
                case 'bottom':
                default:
                    $bgY = 'h-' . $bgHeight;
                    break;
            }

            if ($bgStyle === 'solid' || $bgStyle === 'gradient') {
                // Add semi-transparent rectangle
                $filters[] = "drawbox=x=0:y={$bgY}:w=iw:h={$bgHeight}:color={$bgColorFfmpeg}@0.{$bgOpacity}:t=fill";
            } elseif ($bgStyle === 'blur') {
                // For blur effect, we need a more complex filter
                // Simplified: just use a dark overlay
                $filters[] = "drawbox=x=0:y={$bgY}:w=iw:h={$bgHeight}:color=000000@0.5:t=fill";
            }
        }

        // Calculate text positions based on position setting
        $authorFontSize = (int)($titleFontSize * 0.5);
        $chapterFontSize = (int)($titleFontSize * 0.8);

        switch ($position) {
            case 'top':
                $titleY = 40;
                $authorY = $titleY + $titleFontSize + 20;
                $chapterY = $authorY + $authorFontSize + 15;
                break;
            case 'center':
                $titleY = '(h-' . $titleFontSize . ')/2-40';
                $authorY = '(h)/2+20';
                $chapterY = '(h)/2+' . ($authorFontSize + 40);
                break;
            case 'bottom':
            default:
                $chapterY = 'h-50';
                $authorY = 'h-' . (50 + $chapterFontSize + 10);
                $titleY = 'h-' . (50 + $chapterFontSize + $authorFontSize + 30);
                break;
        }

        // Build border option
        $borderOption = $borderWidth > 0 ? ":borderw={$borderWidth}:bordercolor={$borderColorFfmpeg}" : '';

        // Title (large, centered)
        if (!empty($textElements['title'])) {
            $escapedTitle = $this->escapeFFmpegText($textElements['title']);
            $filters[] = "drawtext={$fontFile}text='{$escapedTitle}':fontsize={$titleFontSize}:fontcolor={$textColorFfmpeg}{$borderOption}:x=(w-text_w)/2:y={$titleY}";
        }

        // Author below title
        if (!empty($textElements['author'])) {
            $escapedAuthor = $this->escapeFFmpegText($textElements['author']);
            $authorBorderWidth = max(1, (int)($borderWidth * 0.5));
            $authorBorder = $borderWidth > 0 ? ":borderw={$authorBorderWidth}:bordercolor={$borderColorFfmpeg}" : '';
            $filters[] = "drawtext={$fontFile}text='{$escapedAuthor}':fontsize={$authorFontSize}:fontcolor={$textColorFfmpeg}{$authorBorder}:x=(w-text_w)/2:y={$authorY}";
        }

        // Chapter number
        if (!empty($textElements['chapter'])) {
            $escapedChapter = $this->escapeFFmpegText($textElements['chapter']);
            $chapterBorderWidth = max(2, (int)($borderWidth * 0.75));
            $chapterBorder = $borderWidth > 0 ? ":borderw={$chapterBorderWidth}:bordercolor={$borderColorFfmpeg}" : '';
            $filters[] = "drawtext={$fontFile}text='{$escapedChapter}':fontsize={$chapterFontSize}:fontcolor={$textColorFfmpeg}{$chapterBorder}:x=(w-text_w)/2:y={$chapterY}";
        }

        if (empty($filters)) {
            // No text to add, just copy the image
            copy($inputPath, $outputPath);
            return ['success' => true];
        }

        $filterString = implode(',', $filters);

        $command = sprintf(
            '%s -y -i %s -vf "%s" -q:v 2 %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($inputPath),
            $filterString,
            escapeshellarg($outputPath)
        );

        \Log::info('FFmpeg thumbnail text overlay command', ['command' => $command]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            \Log::error('FFmpeg text overlay failed', [
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            return [
                'success' => false,
                'error' => implode("\n", array_slice($output, -3))
            ];
        }

        return ['success' => true];
    }

    /**
     * Find a font that supports Vietnamese characters
     */
    private function findVietnameseFont(): ?string
    {
        // Windows fonts
        $windowsFonts = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/tahoma.ttf',
            'C:/Windows/Fonts/times.ttf',
            'C:/Windows/Fonts/segoeui.ttf',
        ];

        // Linux fonts
        $linuxFonts = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
        ];

        $fonts = PHP_OS_FAMILY === 'Windows' ? $windowsFonts : $linuxFonts;

        foreach ($fonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return null;
    }

    /**
     * Escape text for FFmpeg drawtext filter
     */
    private function escapeFFmpegText(string $text): string
    {
        // Escape special characters for FFmpeg drawtext
        $text = str_replace(['\\', "'", ':', '%'], ['\\\\', "\\'", '\\:', '\\%'], $text);
        return $text;
    }

    /**
     * Bước 1: Phân tích nội dung sách → trả về danh sách scenes + prompts
     * Tách riêng để debug và cho phép user review trước khi tạo ảnh
     */
    public function analyzeBookForScenes(string $title, string $description, string $category, string $bookType, string $style = 'cinematic'): array
    {
        if (empty($description)) {
            return [
                'success' => false,
                'error' => 'Phần giới thiệu sách trống. Vui lòng nhập nội dung giới thiệu trước.'
            ];
        }

        \Log::info("Bước 1: Phân tích nội dung sách để tạo scenes", [
            'title' => $title,
            'description_length' => strlen($description)
        ]);

        try {
            $sceneDescriptions = $this->analyzeDescriptionForScenes($title, $description, $category, $bookType, null);

            if (empty($sceneDescriptions)) {
                return [
                    'success' => false,
                    'error' => 'AI không thể phân tích nội dung. Vui lòng kiểm tra phần giới thiệu sách.'
                ];
            }

            $totalScenes = count($sceneDescriptions);

            // Build full prompts for each scene
            $scenes = [];
            foreach ($sceneDescriptions as $index => $sceneInfo) {
                $fullPrompt = $this->buildScenePromptFromAnalysis($sceneInfo, $style, $index + 1, $totalScenes);
                $scenes[] = [
                    'scene_number' => $index + 1,
                    'title' => $sceneInfo['title'] ?? 'Scene ' . ($index + 1),
                    'description' => $sceneInfo['description'] ?? '',
                    'visual_prompt' => $sceneInfo['visual_prompt'] ?? '',
                    'full_prompt' => $fullPrompt,
                ];
            }

            \Log::info("Bước 1 hoàn tất: {$totalScenes} scenes được phân tích", [
                'scenes' => array_map(fn($s) => $s['title'], $scenes)
            ]);

            return [
                'success' => true,
                'scenes' => $scenes,
                'total' => $totalScenes,
                'style' => $style
            ];
        } catch (\Exception $e) {
            \Log::error("Analyze scenes failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Lỗi phân tích: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate illustration scenes for video
     * Phân tích nội dung giới thiệu sách bằng AI và tạo các phân cảnh minh họa theo thứ tự logic
     * 
     * @param array $bookInfo Book information
     * @param int $count Number of scenes to generate
     * @param string $style Visual style
     * @return array{success: bool, images?: array, error?: string}
     */
    public function generateVideoScenes(array $bookInfo, ?int $count = null, string $style = 'cinematic'): array
    {
        $bookId = $bookInfo['book_id'] ?? 0;
        $title = $bookInfo['title'] ?? '';
        $description = $bookInfo['description'] ?? '';
        $category = $bookInfo['category'] ?? '';
        $bookType = $bookInfo['book_type'] ?? '';

        \Log::info("Phân tích nội dung sách để tạo scenes minh họa", [
            'book_id' => $bookId,
            'scene_count' => $count
        ]);

        // Phân tích description bằng AI để tạo các phân cảnh logic
        $sceneDescriptions = $this->analyzeDescriptionForScenes($title, $description, $category, $bookType, $count);

        if (empty($sceneDescriptions)) {
            return [
                'success' => false,
                'error' => 'Không thể phân tích nội dung để tạo scenes. Vui lòng kiểm tra phần giới thiệu sách.',
                'images' => [],
                'errors' => []
            ];
        }

        $totalScenes = count($sceneDescriptions);

        $images = [];
        $errors = [];

        foreach ($sceneDescriptions as $index => $sceneInfo) {
            $scenePrompt = $this->buildScenePromptFromAnalysis($sceneInfo, $style, $index + 1, $totalScenes);

            $outputDir = storage_path('app/public/books/' . $bookId . '/scenes');
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $timestamp = time();
            $filename = "scene_{$index}_{$timestamp}.png";
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

            \Log::info("Tạo scene {$index}", [
                'title' => $sceneInfo['title'] ?? '',
                'prompt' => $scenePrompt
            ]);

            $result = $this->generateImage($scenePrompt, $outputPath, '16:9');

            if ($result['success']) {
                $relativePath = 'books/' . $bookId . '/scenes/' . $filename;
                $images[] = [
                    'index' => $index,
                    'path' => $relativePath,
                    'url' => asset('storage/' . $relativePath),
                    'title' => $sceneInfo['title'] ?? "Scene " . ($index + 1),
                    'description' => $sceneInfo['description'] ?? '',
                    'prompt' => $scenePrompt
                ];

                // Save metadata for this scene
                $metadataPath = $outputDir . DIRECTORY_SEPARATOR . "scene_{$index}_{$timestamp}.json";
                file_put_contents($metadataPath, json_encode([
                    'scene_number' => $index + 1,
                    'title' => $sceneInfo['title'] ?? "Scene " . ($index + 1),
                    'description' => $sceneInfo['description'] ?? '',
                    'visual_prompt' => $sceneInfo['visual_prompt'] ?? '',
                    'style' => $style,
                    'created_at' => time()
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $errors[] = "Scene {$index} ({$sceneInfo['title']}): " . ($result['error'] ?? 'Unknown error');
            }

            // Add delay between requests to avoid rate limiting
            if ($index < count($sceneDescriptions) - 1) {
                sleep(2);
            }
        }

        return [
            'success' => count($images) > 0,
            'images' => $images,
            'errors' => $errors,
            'generated' => count($images),
            'failed' => count($errors)
        ];
    }

    /**
     * Phân tích description bằng AI để tạo các scene descriptions logic
     */
    private function analyzeDescriptionForScenes(string $title, string $description, string $category, string $bookType, ?int $count = null): array
    {
        if (empty($description)) {
            \Log::warning("Description rỗng, không thể phân tích scenes");
            return [];
        }

        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            \Log::error("GEMINI_API_KEY not configured");
            // Fallback with default count if not provided
            return $this->fallbackSplitIntoScenes($description, $count ?? 5);
        }

        // Prompt để phân tích description thành các scenes
        $analysisPrompt = $this->buildAnalysisPrompt($title, $description, $category, $bookType, $count);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $analysisPrompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 2048
                ]
            ];

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Parse JSON response
                $scenes = $this->parseSceneAnalysisResponse($text);

                if (!empty($scenes)) {
                    \Log::info("AI phân tích thành công {$count} scenes từ description");
                    return $scenes;
                }
            }

            \Log::warning("AI analysis failed, using fallback method", ['http_code' => $httpCode]);
            return $this->fallbackSplitIntoScenes($description, $count);
        } catch (\Exception $e) {
            \Log::error("Error analyzing description: " . $e->getMessage());
            return $this->fallbackSplitIntoScenes($description, $count);
        }
    }

    /**
     * Build analysis prompt for Gemini
     */
    private function buildAnalysisPrompt(string $title, string $description, string $category, string $bookType, ?int $count = null): string
    {
        $prompt = "Bạn là chuyên gia phân tích văn học và tạo storyboard cho video.\n\n";

        if ($count) {
            $prompt .= "NHIỆM VỤ: Phân tích nội dung giới thiệu sách sau và tạo khoảng {$count} phân cảnh (scenes) minh họa theo thứ tự logic để làm video giới thiệu sách.\n\n";
        } else {
            $prompt .= "NHIỆM VỤ: Phân tích nội dung giới thiệu sách sau và tự động xác định số lượng phân cảnh (scenes) minh họa PHÙ HỢP (thường là 4-8 scenes) để làm video giới thiệu sách.\n\n";
        }

        $prompt .= "THÔNG TIN SÁCH:\n";
        $prompt .= "- Tên sách: {$title}\n";
        if ($category) $prompt .= "- Thể loại: {$category}\n";
        if ($bookType) $prompt .= "- Loại: {$bookType}\n";
        $prompt .= "\nNỘI DUNG GIỚI THIỆU:\n{$description}\n\n";

        $prompt .= "YÊU CẦU:\n";
        $prompt .= "1. Đọc và hiểu toàn bộ nội dung giới thiệu\n";

        if ($count) {
            $prompt .= "2. Xác định {$count} khoảnh khắc/phân cảnh QUAN TRỌNG NHẤT theo thứ tự logic\n";
        } else {
            $prompt .= "2. TỰ ĐỘNG xác định số lượng phân cảnh phù hợp (4-8 scenes) dựa trên độ dài và sự phức tạp của nội dung\n";
            $prompt .= "   - Nội dung ngắn/đơn giản: 3-5 scenes\n";
            $prompt .= "   - Nội dung trung bình: 5-7 scenes\n";
            $prompt .= "   - Nội dung dài/phức tạp: 7-10 scenes\n";
        }

        $prompt .= "3. Mỗi scene nên minh họa một KEY MOMENT hoặc ý chính trong nội dung\n";
        $prompt .= "4. Các scenes phải có tính liên kết, tạo thành một câu chuyện hoàn chỉnh\n";
        $prompt .= "5. Mỗi scene cần mô tả cụ thể hình ảnh để tạo illustration (không phải văn bản)\n\n";

        $prompt .= "OUTPUT FORMAT (JSON):\n";
        $prompt .= "Trả về ĐÚNG định dạng JSON array sau (không có markdown, chỉ JSON thuần):\n";
        $prompt .= "[\n";
        $prompt .= "  {\n";
        $prompt .= "    \"scene_number\": 1,\n";
        $prompt .= "    \"title\": \"Tiêu đề ngắn gọn của scene\",\n";
        $prompt .= "    \"description\": \"Mô tả ngắn gọn nội dung của scene\",\n";
        $prompt .= "    \"visual_prompt\": \"Mô tả chi tiết hình ảnh minh họa: nhân vật, bối cảnh, hành động, không khí, màu sắc... (3-4 câu)\"\n";
        $prompt .= "  },\n";
        $prompt .= "  ...\n";
        $prompt .= "]\n\n";

        $prompt .= "LƯU Ý:\n";
        $prompt .= "- visual_prompt phải MÔ TẢ HÌNH ẢNH cụ thể (VD: 'một người đàn ông đứng trên núi cao nhìn xuống thung lũng, ánh hoàng hôn, không khí hùng vĩ')\n";
        $prompt .= "- KHÔNG viết văn bản hoặc tóm tắt nội dung trong visual_prompt\n";
        $prompt .= "- Tập trung vào VISUAL ELEMENTS: người, vật, bối cảnh, ánh sáng, màu sắc, cảm xúc\n";
        $prompt .= "- Nếu không có nhân vật cụ thể, mô tả bối cảnh/không gian phù hợp với nội dung\n\n";

        $prompt .= "Bắt đầu JSON response:";

        return $prompt;
    }

    /**
     * Parse Gemini analysis response
     */
    private function parseSceneAnalysisResponse(string $text): array
    {
        // Remove markdown code blocks if present
        $text = preg_replace('/```json\s*/i', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
        $text = trim($text);

        // Try to decode JSON
        $scenes = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \Log::warning("Failed to parse JSON response", ['text' => substr($text, 0, 500)]);
            return [];
        }

        if (!is_array($scenes)) {
            return [];
        }

        // Validate and normalize scenes
        $validScenes = [];
        foreach ($scenes as $scene) {
            if (!isset($scene['visual_prompt'])) {
                continue;
            }

            $validScenes[] = [
                'scene_number' => $scene['scene_number'] ?? (count($validScenes) + 1),
                'title' => $scene['title'] ?? 'Scene ' . (count($validScenes) + 1),
                'description' => $scene['description'] ?? '',
                'visual_prompt' => $scene['visual_prompt']
            ];
        }

        return $validScenes;
    }

    /**
     * Build scene prompt from AI analysis
     */
    private function buildScenePromptFromAnalysis(array $sceneInfo, string $style, int $sceneNumber, ?int $totalScenes = null): string
    {
        $stylePrompts = [
            'realistic' => 'photorealistic, high detail, cinematic photography, professional lighting',
            'anime' => 'anime style, vibrant colors, detailed Japanese animation, studio quality',
            'illustration' => 'digital illustration, artistic, storybook style, detailed artwork',
            'cinematic' => 'cinematic quality, dramatic lighting, movie scene, professional composition',
        ];

        $styleDescription = $stylePrompts[$style] ?? $stylePrompts['cinematic'];
        $visualPrompt = $sceneInfo['visual_prompt'] ?? $sceneInfo['description'];

        // Build complete prompt for image generation
        $totalLabel = $totalScenes ? "/{$totalScenes}" : '';
        $prompt = "Scene {$sceneNumber}{$totalLabel}: {$visualPrompt}. ";
        $prompt .= "Style: {$styleDescription}. ";
        $prompt .= "16:9 aspect ratio, no text or letters in image, highly detailed, atmospheric, professional quality.";

        return $prompt;
    }

    /**
     * Fallback method: split description into scenes (simple)
     */
    private function fallbackSplitIntoScenes(string $description, ?int $count = null): array
    {
        $segments = $this->splitIntoScenes($description, $count);

        $scenes = [];
        foreach ($segments as $index => $segment) {
            $scenes[] = [
                'scene_number' => $index + 1,
                'title' => 'Scene ' . ($index + 1),
                'description' => $segment,
                'visual_prompt' => $segment
            ];
        }

        return $scenes;
    }

    /**
     * Split text into scene segments
     */
    private function splitIntoScenes(string $text, ?int $count = null): array
    {
        $count = $count ?? 5;

        if (empty($text)) {
            return array_fill(0, $count, 'A beautiful atmospheric scene');
        }

        // Split by sentences
        $sentences = preg_split('/[.!?。]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentences = array_filter(array_map('trim', $sentences));
        $sentences = array_values($sentences);

        if (count($sentences) <= $count) {
            // Pad with generic scenes if not enough sentences
            while (count($sentences) < $count) {
                $sentences[] = 'A compelling scene from the story';
            }
            return $sentences;
        }

        // Group sentences into segments
        $segmentSize = ceil(count($sentences) / $count);
        $segments = [];

        for ($i = 0; $i < $count; $i++) {
            $start = $i * $segmentSize;
            $segmentSentences = array_slice($sentences, $start, $segmentSize);
            $segments[] = implode('. ', $segmentSentences);
        }

        return $segments;
    }

    /**
     * Build prompt for a specific scene
     */
    private function buildScenePrompt(string $segment, string $category, string $style, int $sceneNumber, int $totalScenes): string
    {
        $stylePrompts = [
            'realistic' => 'photorealistic, high detail, cinematic photography',
            'anime' => 'anime style, vibrant colors, detailed Japanese animation',
            'illustration' => 'digital illustration, artistic, storybook style',
            'cinematic' => 'cinematic, dramatic lighting, movie scene quality',
        ];

        $styleDescription = $stylePrompts[$style] ?? $stylePrompts['cinematic'];

        $prompt = "Create an illustration for scene {$sceneNumber} of {$totalScenes}. ";
        $prompt .= "Style: {$styleDescription}. ";

        if ($category) {
            $prompt .= "Genre: {$category}. ";
        }

        $prompt .= "Scene description: {$segment}. ";
        $prompt .= "Create a visually stunning scene, 16:9 aspect ratio, no text or letters, atmospheric and evocative.";

        return $prompt;
    }

    /**
     * Delete generated media files
     */
    public function deleteMedia(int $bookId, string $type = 'all'): array
    {
        $baseDir = storage_path('app/public/books/' . $bookId);
        $deleted = [];

        if ($type === 'all' || $type === 'thumbnails') {
            $thumbDir = $baseDir . '/thumbnails';
            if (is_dir($thumbDir)) {
                $files = glob($thumbDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $deleted[] = basename($file);
                    }
                }
            }
        }

        if ($type === 'all' || $type === 'scenes') {
            $scenesDir = $baseDir . '/scenes';
            if (is_dir($scenesDir)) {
                $files = glob($scenesDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $deleted[] = basename($file);
                    }
                }
            }
        }

        return [
            'success' => true,
            'deleted' => $deleted,
            'count' => count($deleted)
        ];
    }

    /**
     * Get existing media for a book
     */
    public function getExistingMedia(int $bookId): array
    {
        $baseDir = storage_path('app/public/books/' . $bookId);
        $media = [
            'thumbnails' => [],
            'scenes' => [],
            'animations' => []
        ];

        $thumbDir = $baseDir . '/thumbnails';
        if (is_dir($thumbDir)) {
            $files = glob($thumbDir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
            foreach ($files as $file) {
                if (!is_file($file) || !is_readable($file)) {
                    continue;
                }

                $createdAt = @filemtime($file);
                if ($createdAt === false) {
                    continue;
                }

                $filename = basename($file);
                $relativePath = 'books/' . $bookId . '/thumbnails/' . $filename;
                $media['thumbnails'][] = [
                    'filename' => $filename,
                    'path' => $relativePath,
                    'url' => asset('storage/' . $relativePath),
                    'created_at' => $createdAt
                ];
            }
            // Sort by newest first
            usort($media['thumbnails'], fn($a, $b) => $b['created_at'] - $a['created_at']);
        }

        $scenesDir = $baseDir . '/scenes';
        if (is_dir($scenesDir)) {
            $files = glob($scenesDir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
            foreach ($files as $file) {
                if (!is_file($file) || !is_readable($file)) {
                    continue;
                }

                $createdAt = @filemtime($file);
                if ($createdAt === false) {
                    continue;
                }

                $filename = basename($file);
                $relativePath = 'books/' . $bookId . '/scenes/' . $filename;

                // Try to load metadata
                $metadataFile = str_replace(['.png', '.jpg', '.jpeg', '.webp'], '.json', $file);
                $metadata = null;
                if (file_exists($metadataFile) && is_readable($metadataFile)) {
                    $metadataRaw = @file_get_contents($metadataFile);
                    if ($metadataRaw !== false) {
                        $metadata = json_decode($metadataRaw, true);
                    }
                }

                $media['scenes'][] = [
                    'filename' => $filename,
                    'path' => $relativePath,
                    'url' => asset('storage/' . $relativePath),
                    'title' => $metadata['title'] ?? null,
                    'description' => $metadata['description'] ?? null,
                    'scene_number' => $metadata['scene_number'] ?? null,
                    'created_at' => $createdAt
                ];
            }
            // Sort by index then by time
            usort($media['scenes'], function ($a, $b) {
                // Prioritize scene_number if available
                if (isset($a['scene_number']) && isset($b['scene_number'])) {
                    return $a['scene_number'] - $b['scene_number'];
                }

                // Fallback to filename parsing
                preg_match('/scene_(\d+)/', $a['filename'], $matchA);
                preg_match('/scene_(\d+)/', $b['filename'], $matchB);
                $indexA = (int)($matchA[1] ?? 0);
                $indexB = (int)($matchB[1] ?? 0);
                return $indexA - $indexB;
            });
        }

        // Get animations (MP4 videos from Kling AI)
        $animDir = $baseDir . '/animations';
        if (is_dir($animDir)) {
            $files = glob($animDir . '/*.{mp4,webm}', GLOB_BRACE) ?: [];
            foreach ($files as $file) {
                if (!is_file($file) || !is_readable($file)) {
                    continue;
                }

                $createdAt = @filemtime($file);
                if ($createdAt === false) {
                    continue;
                }

                $filename = basename($file);
                $relativePath = 'books/' . $bookId . '/animations/' . $filename;
                $media['animations'][] = [
                    'filename' => $filename,
                    'path' => $relativePath,
                    'url' => asset('storage/' . $relativePath),
                    'created_at' => $createdAt
                ];
            }
            // Sort by newest first
            usort($media['animations'], fn($a, $b) => $b['created_at'] - $a['created_at']);
        }

        return $media;
    }
}
