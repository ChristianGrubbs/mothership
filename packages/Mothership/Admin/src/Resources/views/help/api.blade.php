<x-admin::layouts>
    <x-slot:title>{{ config('documentation.name') }} API reference</x-slot>
    <div class="flex flex-col gap-6 p-6 text-gray-800 dark:text-white">
        <a href="{{ route('admin.help.index') }}">← Help</a>
        <h1 class="text-2xl font-semibold">API reference</h1>
        <p>Integration endpoints for {{ config('documentation.name') }}. Authentication, filtering and file-transfer guidance is in the API usage article. Documentation language: English.</p>
        <a href="{{ route('admin.help.docs.show', ['slug' => 'api-usage']) }}">Read the API usage guide</a>
        <div class="flex flex-wrap gap-4">
            @foreach (['openapi.json' => 'OpenAPI download', 'routes.json' => 'All registered routes', 'coverage.json' => 'Coverage report'] as $file => $label)
                <a href="{{ route('admin.help.api.download', ['file' => $file]) }}">{{ $label }}</a>
            @endforeach
        </div>
        <p>{{ $coverage['accounted'] }} registered route identities accounted for; {{ $coverage['counts']['api'] }} integration operations. Internal browser and public-link routes remain separately classified in the coverage report.</p>
        @foreach ($document['paths'] as $path => $methods)
            @foreach ($methods as $method => $operation)
                <details id="{{ $operation['operationId'] }}" class="rounded-lg border p-4 dark:border-cherry-800">
                    <summary class="cursor-pointer font-semibold break-all">{{ strtoupper($method) }} {{ $path }} — {{ $operation['summary'] }}</summary>
                    <div class="mt-4 flex flex-col gap-4">
                        <p class="whitespace-pre-wrap">{{ $operation['description'] }}</p>
                        <p>Authentication: {{ $operation['x-surface'] }}. Feature flags: {{ implode(', ', $operation['x-feature-flags']) ?: 'None' }}.</p>
                        @foreach (['parameters' => 'Parameters', 'requestBody' => 'Request body', 'responses' => 'Responses'] as $key => $label)
                            @if (!empty($operation[$key]))
                                <h2 class="font-semibold">{{ $label }}</h2>
                                <pre class="overflow-x-auto rounded bg-gray-100 p-4 text-sm dark:bg-cherry-900"><code>{{ json_encode($operation[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endforeach
        @endforeach
    </div>
</x-admin::layouts>
