<x-app-layout title="Audit logs" :full-width="true">
    <div class="mb-6"><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Administration</p><h1 class="mt-2 text-3xl font-bold">Audit logs</h1><p class="mt-2 text-slate-500">Review recorded changes for your school. History is read-only.</p></div>
    <x-admin-feedback />
    <form action="{{ route('audit.index') }}" method="GET" class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach(['module'=>'Module', 'action'=>'Action', 'entity_type'=>'Record type'] as $field=>$label)
            <label class="text-sm font-semibold">{{ $label }}<select name="{{ $field }}" class="mt-2 w-full rounded-lg border border-slate-300 bg-white p-3"><option value="">All {{ strtolower($label) }}s</option>@foreach($options[$field] as $option)<option value="{{ $option }}" @selected(($filters[$field] ?? '') === $option)>{{ $option }}</option>@endforeach</select></label>
        @endforeach
        @foreach(['user_id'=>'Actor ID', 'entity_id'=>'Record ID'] as $field=>$label)
            <label class="text-sm font-semibold">{{ $label }}<input type="number" min="1" name="{{ $field }}" value="{{ $filters[$field] ?? '' }}" class="mt-2 w-full rounded-lg border border-slate-300 p-3"></label>
        @endforeach
        @foreach(['from'=>'From date', 'to'=>'Through date'] as $field=>$label)
            <label class="text-sm font-semibold">{{ $label }}<input type="date" name="{{ $field }}" value="{{ $filters[$field] ?? '' }}" class="mt-2 w-full rounded-lg border border-slate-300 p-3"></label>
        @endforeach
        <div class="flex flex-wrap items-end gap-3"><button type="submit" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Apply filters</button><a href="{{ route('audit.index') }}" class="rounded-lg border border-slate-300 px-5 py-3">Reset</a></div>
    </form>
    <x-data-table :records="$logs" title="Activity history" icon="audit">
        <table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Time</th><th class="p-4">Actor</th><th class="p-4">Module / Action</th><th class="p-4">Record</th><th class="p-4">Context</th><th class="p-4">Details</th></tr></thead><tbody>
        @forelse($logs as $log)
            <tr class="border-t border-slate-200">
                <td class="whitespace-nowrap p-4">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                <td class="p-4"><span class="font-semibold">{{ $log->context['actor_name'] ?? 'Name not recorded' }}</span><span class="mt-1 block text-xs text-slate-500">Actor #{{ $log->user_id ?? '—' }}</span></td>
                <td class="p-4"><span class="block text-xs text-slate-500">{{ $log->module }}</span><span class="font-semibold">{{ $log->action }}</span></td>
                <td class="p-4">{{ $log->entity_type }} #{{ $log->entity_id }}</td>
                <td class="p-4">@if(isset($log->context['student_name']))<span class="block font-semibold">{{ $log->context['student_name'] }}</span><span class="block text-slate-500">{{ $log->context['subject_name'] ?? '' }}</span>@else<span class="text-slate-500">—</span>@endif</td>
                <td class="p-4"><a href="{{ route('audit.show', $log) }}" class="table-action font-semibold text-[#8b2f24] underline">View changes</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="p-6 text-slate-500">No recorded changes match these filters.</td></tr>
        @endforelse
        </tbody></table>
    </x-data-table>
</x-app-layout>
