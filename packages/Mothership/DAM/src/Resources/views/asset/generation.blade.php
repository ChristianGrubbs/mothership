<x-admin::layouts>
    <x-slot:title>Generation details — {{ $asset_title }}</x-slot:title>
    <div class="flex flex-col gap-6 max-w-6xl mx-auto py-6">
        <div class="flex items-center justify-between gap-4">
            <div><h1 class="text-2xl font-semibold">{{ $asset_title }}</h1><p class="text-gray-500">Generation details</p></div>
            @if ($reuse_url)
                @if (app(\Mothership\DAM\Services\StylePressAccess::class)->permits())
                    <a class="primary-button" href="{{ $reuse_url }}">Reuse in StylePress</a>
                @endif
            @else
                <p class="text-gray-500">No reusable style snapshot was recorded. Copy the prompt below or choose this image as a reference in StylePress.</p>
            @endif
        </div>
        <section class="rounded-lg border bg-white dark:bg-cherry-900 overflow-hidden">
            @if ($is_image)
                <a href="{{ $output_url }}" target="_blank" rel="noopener" title="Open the generated image at full size"><img src="{{ $output_url }}" alt="{{ $asset_title }}" class="w-full max-h-[80vh] object-contain bg-gray-100 dark:bg-cherry-950"></a>
            @endif
            <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                <p class="text-gray-500">Generated output</p>
                <div class="flex flex-wrap gap-2">
                    <a class="secondary-button" href="{{ $output_url }}" target="_blank" rel="noopener">Open full size</a>
                    <a class="secondary-button" href="{{ $download_url }}" data-dam-download data-dam-download-name="{{ $file_name ?: $asset_title }}"@if ($is_image) data-dam-download-thumb="{{ $output_url }}"@endif>Download</a>
                </div>
            </div>
        </section>
        <section class="rounded-lg border p-5 bg-white dark:bg-cherry-900">
            <dl class="grid grid-cols-2 gap-4">
                @foreach (['Model' => $manifest['model'] ?? null, 'Provider' => $manifest['provider'] ?? null, 'Style' => $manifest['style']['title'] ?? null, 'Style version' => $manifest['style']['version'] ?? null, 'Generation ID' => $manifest['generation_id'] ?? null] as $label => $value)
                    <div><dt class="text-gray-500">{{ $label }}</dt><dd class="break-words">{{ $value ?? 'Not recorded' }}</dd></div>
                @endforeach
                @foreach ($manifest['settings'] ?? [] as $label => $value)
                    <div><dt class="text-gray-500">{{ ucfirst(str_replace('_', ' ', $label)) }}</dt><dd>{{ is_scalar($value) ? $value : json_encode($value) }}</dd></div>
                @endforeach
            </dl>
        </section>
        @foreach (['original_prompt'=>'Original request', 'enhanced_prompt'=>'Enhanced prompt', 'prompt'=>'Full generation prompt'] as $key => $label)
            <section><h2 class="text-lg font-semibold mb-2">{{ $label }}</h2><pre class="whitespace-pre-wrap break-words rounded-lg border p-5 text-sm bg-white dark:bg-cherry-900">{{ $manifest[$key] ?? 'Not recorded' }}</pre></section>
        @endforeach
        @if (! empty($manifest['style']['template']))
            <details><summary class="cursor-pointer font-semibold">Saved style template</summary><pre class="whitespace-pre-wrap break-words rounded-lg border p-5 text-sm">{{ $manifest['style']['template'] }}</pre></details>
        @endif
        <section><h2 class="text-lg font-semibold mb-3">Reference media</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @forelse ($reference_urls as $reference)
                    <a class="rounded-lg border p-3 block" href="{{ $reference['url'] }}" target="_blank" rel="noopener">
                        @if (str_starts_with($reference['mime'] ?? '', 'image/'))
                            <img src="{{ $reference['url'] }}" alt="{{ $reference['name'] }}" class="w-full h-48 object-contain mb-2" loading="lazy">
                        @elseif (str_starts_with($reference['mime'] ?? '', 'video/'))
                            <video src="{{ $reference['url'] }}" controls preload="metadata" class="w-full h-48 object-contain mb-2"></video>
                        @endif
                        <span class="font-medium break-words">{{ $reference['name'] }}</span><span class="block text-gray-500">{{ $reference['role'] }} · {{ $reference['mime'] }}</span>
                    </a>
                @empty
                    <p>No reference files were recorded.</p>
                @endforelse
            </div>
        </section>
        <section><h2 class="text-lg font-semibold mb-2">Attached reproduction data</h2>
            <div class="flex flex-col gap-2">@foreach ($attachments as $attachment)<a class="text-blue-600 underline break-words" href="{{ $attachment['url'] }}" target="_blank" rel="noopener">{{ $attachment['label'] }}</a>@endforeach</div>
        </section>
    </div>
</x-admin::layouts>
