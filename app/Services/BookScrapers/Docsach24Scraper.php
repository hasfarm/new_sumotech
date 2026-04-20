<?php

namespace App\Services\BookScrapers;

use Illuminate\Support\Facades\Http;

class Docsach24Scraper
{
    protected $url;
    protected $chapters = [];
    protected $bookInfo = [];

    public function __construct($url)
    {
        $this->url = $url;
    }

    /**
     * Scrape chapters from docsach24.co
     */
    public function scrape()
    {
        try {
            $html = $this->fetchHtml($this->url);
            if (!$html) {
                return ['error' => 'Không thể tải trang web'];
            }

            $this->scrapeBookInfo($html);
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

        // Title - prefer h1, fallback to title tag
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches)) {
            $this->bookInfo['title'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $title = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            $title = preg_replace('/\s*\|\s*Docsach24.*$/i', '', $title);
            $this->bookInfo['title'] = trim($title);
        }

        // Author
        if (preg_match('/Tác\s*giả\s*:\s*<a[^>]*>([^<]+)<\/a>/iu', $html, $matches)) {
            $this->bookInfo['author'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/Tác\s*giả\s*:\s*([^<\n]+)/iu', $html, $matches)) {
            $this->bookInfo['author'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }

        // Category/Genre
        if (preg_match('/Thể\s*loại\s*:\s*<a[^>]*>([^<]+)<\/a>/iu', $html, $matches)) {
            $this->bookInfo['category'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/Thể\s*loại\s*:\s*([^<\n]+)/iu', $html, $matches)) {
            $this->bookInfo['category'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        }

        // Description/Introduction
        if (preg_match('/Giới\s*thiệu\s*sách\s*.*?<div[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            $desc = $this->cleanContent($matches[1]);
            if (mb_strlen($desc, 'UTF-8') > 50) {
                $this->bookInfo['description'] = $desc;
            }
        }

        if (empty($this->bookInfo['description'])) {
            if (preg_match('/Giới\s*thiệu\s*.*?<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
                $desc = $this->cleanContent($matches[1]);
                if (mb_strlen($desc, 'UTF-8') > 50) {
                    $this->bookInfo['description'] = $desc;
                }
            }
        }

        // Cover image
        if (preg_match('/<img[^>]*src=["\']([^"\']*data-images[^"\']+)["\']/i', $html, $matches)) {
            $this->bookInfo['cover_image'] = $matches[1];
        } elseif (preg_match('/<img[^>]*src=["\']([^"\']*filemanager\/data-images[^"\']+)["\']/i', $html, $matches)) {
            $this->bookInfo['cover_image'] = $matches[1];
        } elseif (preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $this->bookInfo['cover_image'] = $matches[1];
        }
    }

    /**
     * Extract chapters from page
     */
    protected function scrapeChapters($html)
    {
        $this->chapters = [];
        $chapterNumber = 1;
        $seenChapterUrls = [];
        $pendingPages = [
            [
                'url' => $this->normalizeUrl($this->url),
                'html' => $html,
            ],
        ];
        $seenPageUrls = [];
        $maxPages = 50;

        while (!empty($pendingPages) && count($seenPageUrls) < $maxPages) {
            $page = array_shift($pendingPages);
            $pageUrl = $page['url'];

            if ($pageUrl === '' || isset($seenPageUrls[$pageUrl])) {
                continue;
            }

            $seenPageUrls[$pageUrl] = true;

            $pageHtml = $page['html'];
            if ($pageHtml === null) {
                $pageHtml = $this->fetchHtml($pageUrl);
            }

            if (!$pageHtml) {
                continue;
            }

            $this->extractChaptersFromHtml($pageHtml, $chapterNumber, $seenChapterUrls);

            $nextPageUrls = $this->extractPaginationUrls($pageHtml, $pageUrl);
            foreach ($nextPageUrls as $nextPageUrl) {
                if (!isset($seenPageUrls[$nextPageUrl])) {
                    $pendingPages[] = [
                        'url' => $nextPageUrl,
                        'html' => null,
                    ];
                }
            }
        }
    }

    /**
     * Extract chapter links from a chapter-list page.
     */
    protected function extractChaptersFromHtml($html, &$chapterNumber, array &$seenChapterUrls)
    {
        if (!preg_match_all('/<a[^>]*href=["\']([^"\']*\/doc-sach\/[^"\']+)["\'][^>]*>([^<]+)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $chapterUrl = $this->normalizeUrl(trim($match[1]));
            $chapterTitle = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');

            if ($chapterUrl === '' || $chapterTitle === '') {
                continue;
            }

            if (isset($seenChapterUrls[$chapterUrl])) {
                continue;
            }

            $this->chapters[] = [
                'number' => $chapterNumber,
                'title' => $chapterTitle,
                'url' => $chapterUrl,
            ];
            $seenChapterUrls[$chapterUrl] = true;
            $chapterNumber++;
        }
    }

    /**
     * Extract pagination links for the same book page.
     */
    protected function extractPaginationUrls($html, $currentPageUrl)
    {
        $paginationUrls = [];
        $currentUrlParts = parse_url($currentPageUrl);
        $currentPath = $currentUrlParts['path'] ?? '';

        if (!preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $href) {
            $candidateUrl = $this->normalizeUrl($href);
            if ($candidateUrl === '') {
                continue;
            }

            $candidateParts = parse_url($candidateUrl);
            if (($candidateParts['host'] ?? '') !== 'docsach24.co') {
                continue;
            }

            if (($candidateParts['path'] ?? '') !== $currentPath) {
                continue;
            }

            if (empty($candidateParts['query'])) {
                continue;
            }

            parse_str($candidateParts['query'], $queryParams);
            if (!isset($queryParams['page']) || !ctype_digit((string) $queryParams['page'])) {
                continue;
            }

            $pageNumber = (int) $queryParams['page'];
            // Page 1 is already parsed from the initial HTML, so skip it.
            if ($pageNumber <= 1) {
                continue;
            }

            $normalizedUrl = $this->normalizeBookPageUrl($candidateParts['path'], $pageNumber);
            if ($normalizedUrl === $this->normalizeUrl($currentPageUrl)) {
                continue;
            }

            $paginationUrls[$normalizedUrl] = true;
        }

        $urls = array_keys($paginationUrls);
        usort($urls, function ($a, $b) {
            parse_str((string) parse_url($a, PHP_URL_QUERY), $aParams);
            parse_str((string) parse_url($b, PHP_URL_QUERY), $bParams);

            return ((int) ($aParams['page'] ?? 1)) <=> ((int) ($bParams['page'] ?? 1));
        });

        return $urls;
    }

    /**
     * Normalize URL to absolute docsach24.co URL.
     */
    protected function normalizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }

        if ($url[0] === '/') {
            return 'https://docsach24.co' . $url;
        }

        return 'https://docsach24.co/' . ltrim($url, '/');
    }

    /**
     * Build normalized book page URL with page parameter.
     */
    protected function normalizeBookPageUrl($path, $pageNumber)
    {
        $path = '/' . ltrim((string) $path, '/');
        return 'https://docsach24.co' . $path . '?page=' . (int) $pageNumber;
    }

    /**
     * Get chapter content from chapter page
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

            if (preg_match('/<div[^>]*id=["\']content["\'][^>]*>(.*?)<\/div>/is', $html, $matches)) {
                return $this->cleanContent($matches[1]);
            }

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
     * Clean HTML content to get plain text
     */
    protected function cleanContent($html)
    {
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/i', '', $html);
        $html = preg_replace('/<div[^>]*class=["\'][^"\']*(?:ad|nav|sidebar|footer|share|social)[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        $html = preg_replace('/<(?:nav|footer|header|aside)[^>]*>.*?<\/(?:nav|footer|header|aside)>/is', '', $html);
        $html = preg_replace('/\[?\s*(?:quảng cáo|advertisement|sponsored)\s*\]?/i', '', $html);

        $html = str_ireplace(['<br>', '<br/>', '<br />', '<br/>'], "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        $text = strip_tags($html);
        $text = preg_replace('/\n\n+/', "\n\n", $text);
        $text = preg_replace('/ +/', ' ', $text);
        $text = preg_replace('/^\s+/m', '', $text);
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
            "//*[@id='doc-content']",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' doc-content ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' content-doc ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' chapter-content ')]",
            "//div[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'content')]",
            "//section[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'content')]",
            "//article[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'content')]"
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
}
