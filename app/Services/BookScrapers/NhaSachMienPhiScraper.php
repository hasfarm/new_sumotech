<?php

namespace App\Services\BookScrapers;

use Illuminate\Support\Facades\Http;

class NhaSachMienPhiScraper
{
    protected $url;
    protected $chapters = [];
    protected $bookInfo = [];

    public function __construct($url)
    {
        $this->url = $url;
    }

    /**
     * Scrape chapters from nhasachmienphi.com
     */
    public function scrape()
    {
        try {
            $html = $this->fetchHtml($this->url);
            if (!$html) {
                return ['error' => 'Không thể tải trang web'];
            }

            // Extract book info (title, author, category, description)
            $this->scrapeBookInfo($html);

            // Extract chapters
            $this->scrapeChapters($html);

            return [
                'success' => true,
                'title' => $this->bookInfo['title'] ?? 'Sách không xác định',
                'author' => $this->bookInfo['author'] ?? null,
                'category' => $this->bookInfo['category'] ?? null,
                'description' => $this->bookInfo['description'] ?? null,
                'cover_image' => $this->bookInfo['cover_image'] ?? null,
                'chapters' => $this->chapters,
                'total_chapters' => count($this->chapters)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Lỗi scraping: ' . $e->getMessage()];
        }
    }

    /**
     * Fetch HTML content from URL
     */
    protected function fetchHtml($url)
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ])->timeout(30)->get($url);

            if ($response->successful()) {
                return $response->body();
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract book information (title, author, category, description)
     */
    protected function scrapeBookInfo($html)
    {
        $this->bookInfo = [];

        // Title - from h1
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches)) {
            $this->bookInfo['title'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }

        // Author - look for patterns like "Tác giả: xxx" or in meta/spans
        // Pattern 1: "Tác giả:" text
        if (preg_match('/Tác\s*giả\s*:\s*<[^>]*>([^<]+)</iu', $html, $matches)) {
            $this->bookInfo['author'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/Tác\s*giả\s*:\s*([^<\n]+)/iu', $html, $matches)) {
            $this->bookInfo['author'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
        // Pattern 2: Link with author - <a...>Author Name</a> after "Tác giả"
        if (empty($this->bookInfo['author'])) {
            if (preg_match('/Tác\s*giả[^<]*<a[^>]*>([^<]+)<\/a>/iu', $html, $matches)) {
                $this->bookInfo['author'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }
        }

        // Category/Genre - look for "Thể loại:" or "Chuyên mục:"
        if (preg_match('/(?:Thể\s*loại|Chuyên\s*mục)\s*:\s*<[^>]*>([^<]+)</iu', $html, $matches)) {
            $this->bookInfo['category'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/(?:Thể\s*loại|Chuyên\s*mục)\s*:\s*([^<\n]+)/iu', $html, $matches)) {
            $this->bookInfo['category'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
        // Pattern 2: Link with category
        if (empty($this->bookInfo['category'])) {
            if (preg_match('/(?:Thể\s*loại|Chuyên\s*mục)[^<]*<a[^>]*>([^<]+)<\/a>/iu', $html, $matches)) {
                $this->bookInfo['category'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }
        }

        // Description/Introduction - look for description div or "Giới thiệu" section
        // Pattern 1: div with class containing "description" or "intro" or "gioi-thieu"
        if (preg_match('/<div[^>]*class=["\'][^"\']*(?:description|intro|gioi-thieu|noidung|content)[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches)) {
            $desc = strip_tags($matches[1]);
            $desc = html_entity_decode(trim($desc), ENT_QUOTES, 'UTF-8');
            $desc = preg_replace('/\s+/', ' ', $desc);
            if (mb_strlen($desc) > 50) {
                $this->bookInfo['description'] = $desc;
            }
        }

        // Pattern 2: Look for "Giới thiệu" header followed by content
        if (empty($this->bookInfo['description'])) {
            if (preg_match('/(?:Giới\s*thiệu|Tóm\s*tắt|Mô\s*tả)[^<]*<\/[^>]+>\s*<[^>]+>(.{100,}?)<\/(?:div|p)/is', $html, $matches)) {
                $desc = strip_tags($matches[1]);
                $desc = html_entity_decode(trim($desc), ENT_QUOTES, 'UTF-8');
                $desc = preg_replace('/\s+/', ' ', $desc);
                $this->bookInfo['description'] = $desc;
            }
        }

        // Pattern 3: nhasachmienphi specific - content before chapters in pd-lr-30
        if (empty($this->bookInfo['description'])) {
            if (preg_match('/<div[^>]*class=["\']pd-lr-30["\'][^>]*>(.*?)<\/div>/is', $html, $matches)) {
                $desc = strip_tags($matches[1]);
                $desc = html_entity_decode(trim($desc), ENT_QUOTES, 'UTF-8');
                $desc = preg_replace('/\s+/', ' ', $desc);
                // Only use if it's a reasonable description length
                if (mb_strlen($desc) > 100 && mb_strlen($desc) < 5000) {
                    $this->bookInfo['description'] = $desc;
                }
            }
        }

        // Cover image - look for img in book info area
        if (preg_match('/<img[^>]*class=["\'][^"\']*(?:cover|book|sach)[^"\']*["\'][^>]*src=["\']([^"\']+)["\']/i', $html, $matches)) {
            $this->bookInfo['cover_image'] = $matches[1];
        } elseif (preg_match('/<div[^>]*class=["\'][^"\']*(?:book|cover)[^"\']*["\'][^>]*>.*?<img[^>]*src=["\']([^"\']+)["\']/is', $html, $matches)) {
            $this->bookInfo['cover_image'] = $matches[1];
        }
    }

    /**
     * Get book title from page (legacy method for compatibility)
     */
    protected function scrapeTitle($html)
    {
        // nhasachmienphi.com uses h1 for book title
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }
        return 'Sách không xác định';
    }

    /**
     * Extract chapters from page
     * nhasachmienphi.com structure:
     * <div class='box_chhr'> 
     *   <div class='item_ch_mora'>
     *     <div class='item_ch'><a href='...'>Chương X</a></div>
     *   </div>
     * </div>
     */
    protected function scrapeChapters($html)
    {
        $this->chapters = [];
        $chapterNumber = 1;

        // Pattern 1: nhasachmienphi.com specific - div.item_ch with links
        // <div class='item_ch'><a target="_blank" href='https://nhasachmienphi.com/doc-online/...'>Chương X</a></div>
        if (preg_match_all("/<div class=['\"]item_ch['\"]><a[^>]*href=['\"]([^'\"]+)['\"][^>]*>([^<]+)<\/a><\/div>/i", $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $chapterUrl = trim($match[1]);
                $chapterTitle = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');

                if (!empty($chapterUrl) && !empty($chapterTitle)) {
                    $this->chapters[] = [
                        'number' => $chapterNumber,
                        'title' => $chapterTitle,
                        'url' => $chapterUrl
                    ];
                    $chapterNumber++;
                }
            }
        }

        // Fallback Pattern 2: Links with "Chương" in text inside box_chhr
        if (empty($this->chapters)) {
            if (preg_match('/<div[^>]*class=["\'][^"\']*box_chhr[^"\']*["\'][^>]*>(.*?)<\/div>\s*<\/div>/is', $html, $boxMatch)) {
                $boxHtml = $boxMatch[1];
                if (preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>([^<]*Chương[^<]*)<\/a>/i', $boxHtml, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $chapterUrl = trim($match[1]);
                        $chapterTitle = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');

                        if (!empty($chapterUrl) && !empty($chapterTitle)) {
                            $this->chapters[] = [
                                'number' => $chapterNumber,
                                'title' => $chapterTitle,
                                'url' => $chapterUrl
                            ];
                            $chapterNumber++;
                        }
                    }
                }
            }
        }

        // Fallback Pattern 3: Any link with "doc-online" in href
        if (empty($this->chapters)) {
            if (preg_match_all('/<a[^>]*href=["\']([^"\']*doc-online[^"\']*)["\'][^>]*>([^<]+)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $chapterUrl = trim($match[1]);
                    $chapterTitle = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');

                    if (!empty($chapterUrl) && !empty($chapterTitle)) {
                        $this->chapters[] = [
                            'number' => $chapterNumber,
                            'title' => $chapterTitle,
                            'url' => $chapterUrl
                        ];
                        $chapterNumber++;
                    }
                }
            }
        }
    }

    /**
     * Get chapter content from chapter page
     * nhasachmienphi.com structure:
     * <h2 class='mg-t-10'>Chương X</h2>
     * <div class='pd-lr-30'>...content...</div>
     */
    public function scrapeChapterContent($chapterUrl)
    {
        try {
            $html = $this->fetchHtml($chapterUrl);
            if (!$html) {
                return '';
            }

            $domContent = $this->extractChapterContentFromDom($html);
            if ($domContent !== '') {
                return $domContent;
            }

            // Pattern 1: nhasachmienphi.com specific - div.pd-lr-30 (primary content container)
            if (preg_match('/<div[^>]*class=["\'][^"\']*pd-lr-30[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches)) {
                return $this->cleanContent($matches[1]);
            }

            // Pattern 2: Look for div.box_cont (another common container)
            if (preg_match('/<div[^>]*class=["\'][^"\']*box_cont[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches)) {
                return $this->cleanContent($matches[1]);
            }

            // Pattern 3: Common Vietnamese book sites - story-content, chapter-content
            if (preg_match('/<(?:div|section)[^>]*(?:class="[^"]*(?:story|chapter)[-_]?content[^"]*"|id="[^"]*(?:story|chapter)[-_]?content[^"]*")[^>]*>(.*?)<\/(?:div|section)>/is', $html, $matches)) {
                return $this->cleanContent($matches[1]);
            }

            // Pattern 4: Look for large paragraph blocks
            if (preg_match_all('/<p[^>]*>(.+?)<\/p>/is', $html, $paragraphs)) {
                $content = implode("\n", $paragraphs[1]);
                if (strlen($content) > 100) {
                    return $this->cleanContent($content);
                }
            }

            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get chapter title from chapter page
     */
    public function scrapeChapterTitle($chapterUrl)
    {
        try {
            $html = $this->fetchHtml($chapterUrl);
            if (!$html) {
                return '';
            }

            // Pattern 1: h2.mg-t-10 (nhasachmienphi.com specific)
            if (preg_match('/<h2[^>]*class=["\'][^"\']*mg-t-10[^"\']*["\'][^>]*>([^<]+)<\/h2>/i', $html, $matches)) {
                return html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }

            // Pattern 2: Any h2 tag
            if (preg_match('/<h2[^>]*>([^<]+)<\/h2>/i', $html, $matches)) {
                return html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            }

            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Clean HTML content to get plain text
     */
    protected function cleanContent($html)
    {
        // Remove scripts and styles
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/i', '', $html);

        // Remove ads and navigation
        $html = preg_replace('/<div[^>]*class=["\'][^"\']*(?:ad|nav|sidebar|footer|share|social)[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        $html = preg_replace('/<(?:nav|footer|header|aside)[^>]*>.*?<\/(?:nav|footer|header|aside)>/is', '', $html);

        // Remove inline ads text
        $html = preg_replace('/\[?\s*(?:quảng cáo|advertisement|sponsored)\s*\]?/i', '', $html);

        // Convert br to newline (preserve paragraph breaks)
        $html = str_ireplace(['<br>', '<br/>', '<br />', '<br/>'], "\n", $html);

        // Convert p tag to double newline (paragraph break)
        $html = preg_replace('/<\/p>/i', "\n\n", $html);

        // Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        // Remove remaining HTML tags
        $text = strip_tags($html);

        // Clean up excess whitespace but preserve paragraph structure
        $text = preg_replace('/\n\n+/', "\n\n", $text); // Max 2 newlines
        $text = preg_replace('/ +/', ' ', $text); // Single spaces
        $text = preg_replace('/^\s+/m', '', $text); // Trim leading spaces on each line
        $text = trim($text);

        return $text;
    }

    /**
     * Extract chapter content using DOM to avoid regex truncation with nested divs.
     */
    protected function extractChapterContentFromDom($html)
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $queries = [
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' pd-lr-30 ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' box_cont ')]",
            "//div[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'chapter') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'story')]",
            "//section[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'chapter') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'story')]",
            "//article[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'chapter') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'story')]",
            "//*[@id and (contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'chapter') or contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'story'))]"
        ];

        $bestContent = '';
        $bestLen = 0;

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $innerHtml = $this->getInnerHtml($node);
                if ($innerHtml === '') {
                    continue;
                }

                $cleaned = $this->cleanContent($innerHtml);
                $len = mb_strlen($cleaned, 'UTF-8');

                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestContent = $cleaned;
                }
            }
        }

        return $bestLen > 0 ? $bestContent : '';
    }

    /**
     * Get inner HTML from a DOM node.
     */
    protected function getInnerHtml(\DOMNode $node)
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    /**
     * Get base URL from full URL
     */
    protected function getBaseUrl()
    {
        $parts = parse_url($this->url);
        return $parts['scheme'] . '://' . $parts['host'];
    }
}
