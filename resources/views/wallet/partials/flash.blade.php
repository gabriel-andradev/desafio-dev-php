{{-- Same feedback banner as the Node ActionAlert component. --}}
@if (session('status'))
    <div role="alert" class="flex items-center gap-2.5 p-3.5 rounded-lg border text-xs sm:text-sm bg-emerald-50 border-emerald-200 text-emerald-800">
        <x-icon name="check-circle" class="w-4 h-4 shrink-0 text-emerald-600" />
        <span>{{ session('status') }}</span>
    </div>
@elseif ($errors->any())
    <div role="alert" class="flex items-center gap-2.5 p-3.5 rounded-lg border text-xs sm:text-sm bg-rose-50 border-rose-200 text-rose-800">
        <x-icon name="alert-circle" class="w-4 h-4 shrink-0 text-rose-600" />
        <span>{{ $errors->first() }}</span>
    </div>
@endif
