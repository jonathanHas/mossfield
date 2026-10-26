{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($site['pages'] as $file => $label)
    <url><loc>{{ $site['base_url'] }}/{{ $file === 'index.html' ? '' : $file }}</loc></url>
@endforeach
</urlset>
