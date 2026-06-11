<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\LandingLocale;
use Illuminate\Http\Response;

final class LandingSitemapController extends Controller
{
    public function __invoke(): Response
    {
        $pages = ['home', 'calculator'];
        $lastmod = now()->toAtomString();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">',
        ];

        foreach ($pages as $page) {
            foreach (LandingLocale::supported() as $locale) {
                $loc = htmlspecialchars(LandingLocale::pageUrl($page, $locale), ENT_XML1);
                $lines[] = '  <url>';
                $lines[] = '    <loc>'.$loc.'</loc>';
                $lines[] = '    <lastmod>'.$lastmod.'</lastmod>';

                foreach (LandingLocale::alternates($page) as $alternate) {
                    $href = htmlspecialchars($alternate['href'], ENT_XML1);
                    $hreflang = htmlspecialchars($alternate['hreflang'], ENT_XML1);
                    $lines[] = '    <xhtml:link rel="alternate" hreflang="'.$hreflang.'" href="'.$href.'" />';
                }

                $lines[] = '  </url>';
            }
        }

        $lines[] = '</urlset>';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
