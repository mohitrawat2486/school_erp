<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'module' => 'nullable|string|max:255',
            'action' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer|min:1',
            'entity_type' => 'nullable|string|max:255',
            'entity_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $query = AuditLog::query();
        foreach (['module', 'action', 'user_id', 'entity_type', 'entity_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
        $options = [];
        foreach (['module', 'action', 'entity_type'] as $field) {
            $options[$field] = AuditLog::select($field)->distinct()->orderBy($field)->pluck($field);
        }

        return view('administration.audit-logs', [
            'logs' => $query->orderByDesc('created_at')->orderByDesc('id')->paginateTable(25)->withQueryString(),
            'filters' => $filters,
            'options' => $options,
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        $old = $auditLog->old_values ?? [];
        $new = $auditLog->new_values ?? [];
        $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
        $changes = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $old) || ! array_key_exists($key, $new) || $old[$key] !== $new[$key]) {
                $changes[$key] = ['old_present' => array_key_exists($key, $old), 'new_present' => array_key_exists($key, $new), 'old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

        return view('administration.audit-log', ['log' => $auditLog, 'changes' => $changes]);
    }
}
