{{--
    My reports: the signed-in person's own reports, read-only, with the status
    changes and admin replies marked "visible to reporter". FeedbackController::present() has already dropped the rest.
--}}
@php
    $statusClass = [
        'new'         => 'bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
        'triaged'     => 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
        'in_progress' => 'bg-violet-50 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
        'fixed'       => 'bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200',
        'wont_fix'    => 'bg-gray-100 text-gray-700 dark:bg-cherry-800 dark:text-gray-300',
    ];
@endphp

<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.feedback.mine.title')
    </x-slot:title>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-xl font-bold text-gray-800 dark:text-white">@lang('dam::app.admin.feedback.mine.title')</h1>
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('dam::app.admin.feedback.mine.subtitle')</p>
        </div>

        <button type="button" class="primary-button" data-dam-feedback-open>
            @lang('dam::app.admin.feedback.mine.new')
        </button>
    </div>

    <div class="mt-4 overflow-hidden rounded-lg bg-white box-shadow dark:bg-cherry-900" data-feedback-mine>
        @if ($reports === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.feedback.mine.empty')</p>
        @else
            <div class="hidden md:grid grid-cols-[minmax(0,1fr)_7rem_8rem_8rem] gap-3 border-b bg-primary-50 px-4 py-2.5 text-sm font-semibold text-gray-600 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300">
                <span>@lang('dam::app.admin.feedback.mine.report')</span>
                <span>@lang('dam::app.admin.feedback.mine.type')</span>
                <span>@lang('dam::app.admin.feedback.mine.status')</span>
                <span>@lang('dam::app.admin.feedback.mine.updated')</span>
            </div>

            <ul class="divide-y dark:divide-cherry-800">
                @foreach ($reports as $report)
                    @php
                        $replies = array_values(array_filter($report['notes'] ?? [], fn (array $note): bool => $note['kind'] === 'status_change' || filled($note['body'])));
                        $where = $report['hub_app'] ?: ($report['route_name'] ?: $report['page_url']);
                    @endphp

                    <li class="grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,1fr)_7rem_8rem_8rem] md:items-start md:gap-3" data-feedback-report="{{ $report['id'] }}">
                        <div class="grid min-w-0 gap-0.5">
                            <b class="break-words text-sm font-semibold text-gray-800 dark:text-white">{{ $report['title'] }}</b>
                            <span class="truncate text-xs text-gray-500 dark:text-gray-400">#{{ $report['id'] }}@if ($where) · {{ $where }}@endif</span>

                            <details class="mt-1 text-sm">
                                    <summary class="cursor-pointer text-xs font-medium text-primary-600 dark:text-primary-400">
                                        @lang('dam::app.admin.feedback.mine.replies') ({{ count($replies) }})
                                    </summary>

                                    <div class="mt-2 grid gap-2">
                                        <p class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $report['description'] }}</p>

                                        @if ($report['screenshot_url'])
                                            <a href="{{ $report['screenshot_url'] }}" target="_blank" rel="noopener" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                @lang('dam::app.admin.feedback.mine.screenshot')
                                            </a>
                                        @endif

                                        @foreach ($replies as $note)
                                            <div class="rounded bg-gray-50 px-3 py-2 dark:bg-cherry-800">
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ $note['author'] ?? '—' }} · {{ $note['created_at'] ? \Illuminate\Support\Carbon::parse($note['created_at'])->diffForHumans() : '' }}
                                                </p>
                                                @if ($note['kind'] === 'status_change')
                                                    <p class="text-gray-800 dark:text-gray-200">@lang('dam::app.admin.feedback.mine.status-change', ['status' => trans('dam::app.admin.feedback.mine.statuses.'.$note['to_status'])])</p>
                                                @else
                                                    <p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $note['body'] }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                        </div>

                        <span class="text-sm text-gray-700 dark:text-gray-300">@lang('dam::app.admin.feedback.ui.type'.ucfirst($report['type']))</span>

                        <span>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass[$report['status']] ?? $statusClass['new'] }}">
                                @lang('dam::app.admin.feedback.mine.statuses.'.$report['status'])
                            </span>
                        </span>

                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $report['updated_at'] ? \Illuminate\Support\Carbon::parse($report['updated_at'])->diffForHumans() : '' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-admin::layouts>
