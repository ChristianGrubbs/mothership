@props(['title' => ''])

@php
    // Every tab reads "<Page> — The Mothership" (UI QA F6). Page views pass only the page name; a title
    // that already ends with " — <product>" is kept as it is, and an empty one is the name alone.
    $productName = config('documentation.name');

    $pageTitle = trim(html_entity_decode(strip_tags((string) $title), ENT_QUOTES | ENT_HTML5));

    $documentTitle = match (true) {
        $pageTitle === '', $pageTitle === $productName => $productName,
        str_ends_with($pageTitle, ' — '.$productName)  => $pageTitle,
        default                                        => $pageTitle.' — '.$productName,
    };
@endphp

<title>{{ $documentTitle }}</title>
