<div class="space-y-6 print:space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-3xl font-black uppercase tracking-tighter text-slate-800">Sales History</h1>
            <p class="mt-2 text-xs font-bold uppercase tracking-[0.2em] text-slate-600">Daily archives and Z-Read summaries</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 print:hidden">
            <a :href="salesExportUrl"
                class="inline-flex w-fit items-center gap-2 rounded-lg bg-slate-800 px-4 py-2.5 text-xs font-black text-white hover:bg-slate-700">
                <i class="fas fa-file-csv"></i> Excel / CSV
            </a>
            <button type="button" @click="window.print()"
                class="inline-flex w-fit items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-50">
                <i class="fas fa-file-pdf text-red-700"></i> Print / Save PDF
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="clay-card p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Gross Sales</p>
            <p class="mt-1 text-lg font-black text-slate-800" x-text="formatCurrency(salesShiftReport.summary.gross_sales)"></p>
        </div>
        <div class="clay-card p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Net Sales</p>
            <p class="mt-1 text-lg font-black text-slate-800" x-text="formatCurrency(salesShiftReport.summary.net_sales)"></p>
        </div>
        <div class="clay-card p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">VAT</p>
            <p class="mt-1 text-lg font-black text-slate-800" x-text="formatCurrency(salesShiftReport.summary.vat_total)"></p>
        </div>
        <div class="clay-card p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Total Sales</p>
            <p class="mt-1 text-lg font-black text-[#800000]" x-text="formatCurrency(salesShiftReport.summary.total_sales)"></p>
        </div>
    </div>

    <div class="clay-card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/50 p-5">
            <h2 class="text-xs font-black uppercase tracking-widest text-slate-700">Daily Shift Reports</h2>
            <span class="text-xs font-bold text-slate-500" x-text="salesShiftReport.daily_reports.length + ' shift(s)'"></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1120px]">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Business Date</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Start Time</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Business Cut-off</th>
                        <th class="px-4 py-3 text-right text-[10px] font-black uppercase text-slate-500">Orders / Items</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Opening Float</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Gross</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Discounts</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Net</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">VAT</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Total</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Expected Drawer</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase text-slate-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="report in salesShiftReport.daily_reports" :key="report.id">
                        <tr>
                            <td class="px-4 py-4 text-center text-sm font-bold text-slate-700" x-text="formatBusinessDate(report.business_date)"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold text-slate-700" x-text="formatShiftTime(report.started_at)"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold text-slate-700"
                                x-text="report.cutoff_time ? formatCutoffTime(report.cutoff_time) : 'Not recorded'"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold">
                                <span x-text="formatQuantity(report.summary.order_count)"></span>
                                <span class="text-slate-400"> / </span>
                                <span x-text="formatQuantity(report.summary.items_sold)"></span>
                            </td>
                            <td class="px-4 py-4 text-center text-xs font-bold" x-text="formatCurrency(report.opening_float)"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold" x-text="formatCurrency(report.summary.gross_sales)"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold" x-text="formatCurrency(report.summary.discount_total)"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold" x-text="formatCurrency(report.summary.net_sales)"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold" x-text="formatCurrency(report.summary.vat_total)"></td>
                            <td class="px-4 py-4 text-center text-xs font-black text-[#800000]" x-text="formatCurrency(report.summary.total_sales)"></td>
                            <td class="px-4 py-4 text-center text-xs font-bold" x-text="formatCurrency(report.summary.expected_cash_drawer)"></td>
                            <td class="px-4 py-4 text-center">
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase"
                                    :class="report.status === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                                    x-text="report.status"></span>
                            </td>
                        </tr>
                    </template>
                    <template x-if="salesShiftReport.daily_reports.length === 0">
                        <tr>
                            <td colspan="12" class="px-6 py-10 text-center font-bold text-slate-400">No sales reports for this date range.</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
