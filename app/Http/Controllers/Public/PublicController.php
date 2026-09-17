<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Destinations\ConnectorRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicController extends Controller
{
    public function home(ConnectorRegistry $registry): View
    {
        return view('public.home', ['platforms' => $registry->definitions(), 'meta' => $this->meta('ONE LIVE EVERYWHERE – Stream Once, Reach Everywhere', 'Multi platform live streaming software by AK Computer, Dwarka. Send one OBS stream to YouTube, Facebook and any RTMP destination simultaneously.')]);
    }

    public function features(): View
    {
        return view('public.features', ['meta' => $this->meta('Features – Multi Platform Live Streaming', 'RTMP ingest, multi-destination distribution, scheduling, recording, analytics and automatic updates.')]);
    }

    public function howItWorks(): View
    {
        return view('public.how-it-works', ['meta' => $this->meta('How It Works – OBS Multi Streaming', 'OBS → our RTMP server → streaming engine → YouTube, Facebook and custom RTMP at the same time.')]);
    }

    public function platforms(ConnectorRegistry $registry): View
    {
        return view('public.platforms', ['platforms' => $registry->definitions(), 'meta' => $this->meta('Supported Platforms', 'YouTube Live, Facebook Page Live, Twitch, LinkedIn Live (custom RTMP), Instagram (Live Producer) and any RTMP server.')]);
    }

    public function pricing(): View
    {
        return view('public.pricing', ['meta' => $this->meta('Pricing', 'Flexible plans for events, temples, hotels and businesses in Gujarat and beyond.')]);
    }

    public function faq(): View
    {
        return view('public.faq', ['meta' => $this->meta('FAQ – Live Streaming Software', 'Common questions about OBS multi streaming, RTMP servers and platform support.')]);
    }

    public function contact(): View
    {
        return view('public.contact', ['meta' => $this->meta('Contact AK Computer, Dwarka', 'Live streaming setup and support in Dwarka, Gujarat. Call 9978123146.')]);
    }

    public function sitemap(): Response
    {
        $urls = collect(['home', 'features', 'how-it-works', 'platforms', 'pricing', 'faq', 'contact'])->map(fn ($r) => route($r));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$urls->map(fn ($u) => '<url><loc>'.e($u).'</loc><changefreq>weekly</changefreq></url>')->implode('').'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $txt = "User-agent: *\nDisallow: /admin\nDisallow: /install\nDisallow: /api\nDisallow: /internal\nDisallow: /webhooks\nSitemap: ".route('sitemap')."\n";

        return response($txt, 200, ['Content-Type' => 'text/plain']);
    }

    private function meta(string $title, string $description): array
    {
        return ['title' => $title, 'description' => $description, 'canonical' => url()->current()];
    }
}
