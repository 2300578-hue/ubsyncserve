<div class="clay-card mb-8 flex flex-col gap-5 p-5 print:hidden">
    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">Sales Day</p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <p class="text-sm font-black text-slate-800"
                    x-text="formatBusinessDate(currentShift?.status === 'open' ? currentShift.business_date : businessDate)"></p>
                <span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest"
                    :class="currentShift?.status === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                    x-text="currentShift?.status === 'open' ? 'Open — recording sales' : 'Closed — ready to start'"></span>
            </div>
            <p class="mt-1 text-xs font-semibold text-slate-500"
                x-text="currentShift?.status === 'open' ? 'Press End Day when selling is finished.' : 'Enter the Starting Cash'"></p>
            <p x-show="salesShiftError" x-text="salesShiftError" class="mt-2 text-xs font-bold text-red-700" role="alert"></p>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                Period
                <select x-model="salesPreset" @change="loadAnalytics()"
                    class="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700">
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="custom">Custom range</option>
                </select>
            </label>
            <template x-if="salesPreset === 'custom'">
                <div class="flex items-end gap-2">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                        From
                        <input type="date" x-model="salesFromDate" @change="loadAnalytics()"
                            class="mt-1 block rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700">
                    </label>
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                        To
                        <input type="date" x-model="salesToDate" @change="loadAnalytics()"
                            class="mt-1 block rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700">
                    </label>
                </div>
            </template>
            <a :href="salesExportUrl"
                class="inline-flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-xs font-black text-white hover:bg-slate-700">
                <i class="fas fa-file-csv"></i> Excel / CSV
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_minmax(140px,0.65fr)_minmax(170px,0.8fr)_minmax(220px,1fr)] xl:items-end">
        <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">
            Starting Cash
            <input type="number" min="0" step="0.01" x-model="openingFloat" :disabled="currentShift?.status === 'open' || isSavingSalesShift"
                class="mt-1 block h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 disabled:bg-slate-100">
        </label>
        <button type="button" @click="startSalesShift()" :disabled="isSavingSalesShift || currentShift?.status === 'open'"
            class="h-10 w-full self-end rounded-lg bg-emerald-700 px-4 text-xs font-black uppercase tracking-wide text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">
            <i class="fas fa-play mr-1"></i>
            <span x-text="isSavingSalesShift ? 'Processing…' : 'Start Day'"></span>
        </button>
        <button type="button" @click="closeSalesShift()" :disabled="isSavingSalesShift || currentShift?.status !== 'open'"
            class="h-10 w-full self-end rounded-lg bg-[#800000] px-3 text-[11px] font-black uppercase tracking-wide text-white hover:bg-red-900 disabled:cursor-not-allowed disabled:opacity-50">
            <i class="fas fa-stop mr-1"></i>
            <span x-text="isSavingSalesShift ? 'Processing…' : 'End Day / Z-Read'"></span>
        </button>
        <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">
            Business Cut-off
            <input type="time" x-model="businessDayCutoff" @change="saveBusinessDayCutoff()" :disabled="currentShift?.status === 'open' || isSavingSalesShift"
                class="mt-1 block h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 disabled:bg-slate-100">
        </label>
    </div>
</div>
