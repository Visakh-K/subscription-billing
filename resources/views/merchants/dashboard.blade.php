<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>{{ $metrics['merchant']['name'] }} · Usage dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto flex w-full max-w-7xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
            <div class="flex items-center gap-4">
                <div class="flex size-12 items-center justify-center rounded-2xl bg-indigo-600 text-lg font-bold text-white shadow-lg shadow-indigo-600/20">
                    {{ mb_strtoupper(mb_substr($metrics['merchant']['name'], 0, 1)) }}
                </div>
                <div>
                    <p class="text-sm font-semibold text-indigo-600">Merchant analytics</p>
                    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $metrics['merchant']['name'] }}</h1>
                </div>
            </div>
            <div class="inline-flex w-fit items-center gap-2 rounded-full border border-emerald-200 bg-white px-3 py-2 text-sm font-medium text-emerald-700 shadow-sm">
                <span class="size-2 rounded-full bg-emerald-500"></span>
                Billing systems operational
            </div>
        </header>

        <section aria-label="Current month overview" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Current Cycle Usage</p>
                <div class="mt-4 flex items-end justify-between gap-3">
                    <p class="text-3xl font-bold tracking-tight">{{ number_format($metrics['summary']['current_cycle_usage']) }}</p>
                    <span class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">units</span>
                </div>
                <p class="mt-3 text-sm text-slate-500">
                    {{ number_format($metrics['summary']['total_allowance']) }} units included
                </p>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Projected Overage Revenue</p>
                <div class="mt-4 flex items-end justify-between gap-3">
                    <p class="text-3xl font-bold tracking-tight">{{ number_format($metrics['summary']['projected_overage_revenue'], 2) }}</p>
                    <span class="rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">projected</span>
                </div>
                <p class="mt-3 text-sm text-slate-500">
                    {{ number_format($metrics['summary']['actual_overage_revenue_to_date'], 2) }} actual to date
                </p>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Active Plan</p>
                <p class="mt-4 text-2xl font-bold tracking-tight">{{ $metrics['summary']['active_plan_label'] }}</p>
                <p class="mt-3 text-sm text-slate-500">Most common active subscription</p>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">Customers at Risk</p>
                <p class="mt-4 text-3xl font-bold tracking-tight">{{ count($metrics['churn_risk_customers']) }}</p>
                <p class="mt-3 text-sm text-slate-500">Usage drop greater than 50%</p>
            </article>
        </section>

        <section class="grid gap-6 xl:grid-cols-5">
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-3">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="font-semibold">Top 5 Customers by Usage</h2>
                        <p class="mt-1 text-sm text-slate-500">Usage in the current calendar month</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">This month</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold sm:px-6">Customer</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Usage</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Allowance</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold sm:px-6">Used</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($metrics['top_customers'] as $customer)
                                <tr>
                                    <td class="px-5 py-4 font-medium text-slate-800 sm:px-6">{{ $customer['name'] }}</td>
                                    <td class="px-4 py-4 text-right tabular-nums">{{ number_format($customer['usage']) }}</td>
                                    <td class="px-4 py-4 text-right text-slate-500 tabular-nums">{{ number_format($customer['allowance']) }}</td>
                                    <td class="px-5 py-4 text-right font-semibold tabular-nums sm:px-6">{{ number_format($customer['allowance_percentage'], 1) }}%</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-slate-500">No active customers yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="font-semibold">Churn Risk</h2>
                        <p class="mt-1 text-sm text-slate-500">Usage dropped more than 50% month over month</p>
                    </div>
                    <span class="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">
                        {{ count($metrics['churn_risk_customers']) }} at risk
                    </span>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($metrics['churn_risk_customers'] as $customer)
                        <li class="flex items-center justify-between gap-4 px-5 py-4 sm:px-6">
                            <div>
                                <p class="font-medium text-slate-800">{{ $customer['name'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ number_format($customer['previous_month_usage']) }} → {{ number_format($customer['current_month_usage']) }} units
                                </p>
                            </div>
                            <span class="shrink-0 rounded-lg bg-rose-50 px-2.5 py-1.5 text-sm font-semibold text-rose-700">
                                −{{ number_format($customer['drop_percentage'], 1) }}%
                            </span>
                        </li>
                    @empty
                        <li class="px-6 py-10 text-center text-sm text-slate-500">No customers meet the churn-risk threshold.</li>
                    @endforelse
                </ul>
            </article>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
                <div>
                    <h2 class="font-semibold">Daily Usage Trend</h2>
                    <p class="mt-1 text-sm text-slate-500">Total merchant usage for the last 30 days</p>
                </div>
                <p class="text-xs font-medium text-slate-400">
                    {{ $metrics['daily_usage_trend'][0]['date'] ?? '' }} — {{ $metrics['daily_usage_trend'][29]['date'] ?? '' }}
                </p>
            </div>
            @php
                $trend = $metrics['daily_usage_trend'];
                $maxUnits = max(1, max(array_column($trend, 'units') ?: [0]));
                $chartPoints = [];
                foreach ($trend as $index => $day) {
                    $x = count($trend) > 1 ? ($index / (count($trend) - 1)) * 720 : 360;
                    $y = 170 - (($day['units'] / $maxUnits) * 140);
                    $chartPoints[] = number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
                }
            @endphp
            <div class="mt-5 overflow-hidden">
                <svg class="h-56 w-full min-w-[36rem]" viewBox="0 0 720 200" role="img" aria-label="Daily usage over the last 30 days">
                    <line x1="0" y1="30" x2="720" y2="30" class="stroke-slate-100" stroke-width="1" />
                    <line x1="0" y1="76" x2="720" y2="76" class="stroke-slate-100" stroke-width="1" />
                    <line x1="0" y1="123" x2="720" y2="123" class="stroke-slate-100" stroke-width="1" />
                    <line x1="0" y1="170" x2="720" y2="170" class="stroke-slate-200" stroke-width="1" />
                    <polyline
                        points="{{ implode(' ', $chartPoints) }}"
                        fill="none"
                        stroke="#4f46e5"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>
            </div>
        </section>

        <section aria-labelledby="system-status-heading" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-2 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="system-status-heading" class="font-semibold">System status</h2>
                    <p class="mt-1 text-sm text-slate-500">Operational details for billing and usage services</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 text-sm font-medium text-emerald-700">
                    <span class="size-2 rounded-full bg-emerald-500"></span>
                    All systems nominal
                </span>
            </div>
            <dl class="grid gap-4 pt-4 sm:grid-cols-3">
                @foreach ($metrics['system_status'] as $label => $status)
                    <div class="flex flex-col gap-1">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ str($label)->replace('_', ' ')->title() }}</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ $status }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <footer class="pb-2 text-center text-xs text-slate-400">
            Usage and overage estimates are based on recorded events and active subscription plans.
        </footer>
    </main>
</body>
</html>
