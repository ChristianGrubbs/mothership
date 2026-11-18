    <label v-if="panoramaCanEdit" class="ml-auto flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
        <span>@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.mode-label')</span>
        <select
            class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1 text-gray-700 dark:text-gray-200 disabled:opacity-50"
            :value="panoramaMode"
            :disabled="panoramaBusy"
            data-dam-panorama-mode
            @change="setPanoramaMode($event.target.value)"
        >
            <option value="{{ \Mothership\DAM\Models\Asset::PANORAMA_AUTO }}">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.mode-auto')</option>
            <option value="{{ \Mothership\DAM\Models\Asset::PANORAMA_FORCE_360 }}">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.mode-force-360')</option>
            <option value="{{ \Mothership\DAM\Models\Asset::PANORAMA_FORCE_FLAT }}">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.mode-force-flat')</option>
        </select>
    </label>
