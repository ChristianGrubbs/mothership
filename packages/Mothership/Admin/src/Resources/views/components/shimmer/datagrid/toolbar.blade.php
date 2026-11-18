{{-- Sized like the real bar (datagrid/toolbar.blade.php): it wraps, and the search gives way first, so no block
     runs past the edge of a narrow list. --}}
<div class="flex gap-4 mt-7 justify-between items-center flex-wrap">
    <!-- Left Toolbar -->
    <div class="flex flex-1 min-w-[240px] gap-x-1">
        <!-- Mass Actions Panel --><!-- Filters And Search Panel -->
        <div class="flex gap-x-1 w-full items-center">
            <!-- Search Panel -->
            <div class="shimmer w-full max-w-xs h-[38px] rounded-lg"></div>

            <!-- Information Panel -->
            <div class="ltr:pl-2.5 rtl:pr-2.5">
                <p class="shimmer w-[75px] h-[17px]"></p>
            </div>
        </div>
    </div>

    <!-- Right Toolbar -->
    <div class="flex gap-x-4">
        <!-- Filters Activation Button -->
        <div class="">
            <div class="shimmer w-[94px] h-[38px] rounded-md"></div>
        </div>

        <div class="flex items-center gap-x-2">
            <div class="shimmer w-[72px] h-[38px] rounded-md"></div>

            <p class="shimmer w-14 h-6"></p>

            <div class="shimmer w-10 h-[38px] rounded-md"></div>

            <div class="shimmer w-[37px] h-6"></div>

            <!-- Pagination -->
            <div class="flex items-center gap-1">
                <div class="shimmer w-[38px] h-[38px] rounded-md"></div>

                <div class="shimmer w-[38px] h-[38px] rounded-md"></div>
            </div>
        </div>
    </div>
</div>
