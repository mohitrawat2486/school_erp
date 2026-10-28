<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Http\Requests\EmployeeIndexRequest;
use App\Http\Requests\SaveEmployeeRequest;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function index(EmployeeIndexRequest $request): View
    {
        $filters = $request->validated();
        $query = Employee::query();
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $query->where(function ($search) use ($pattern) {
                $search->where('employee_number', 'like', $pattern)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern])->orWhere('email', 'like', $pattern)->orWhere('department', 'like', $pattern);
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($type = $filters['type'] ?? null) {
            $query->where('employee_type', $type);
        }

        return view('employees.index', ['employees' => $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(): View
    {
        Gate::authorize('create', Employee::class);

        return view('employees.form', ['employee' => new Employee]);
    }

    public function store(SaveEmployeeRequest $request, EmployeeService $service): RedirectResponse
    {
        $employee = $service->save($request->user(), null, $request->validated(), $request->file('photo'));

        return redirect()->route('employees.show', $employee)->with('status', 'Employee created.');
    }

    public function show(Employee $employee): View
    {
        Gate::authorize('view', $employee);

        return view('employees.show', compact('employee'));
    }

    public function edit(Employee $employee): View
    {
        Gate::authorize('update', $employee);

        return view('employees.form', compact('employee'));
    }

    public function update(SaveEmployeeRequest $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $service->save($request->user(), $employee, $request->validated(), $request->file('photo'));

        return redirect()->route('employees.show', $employee)->with('status', 'Employee saved.');
    }

    public function activate(Request $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $employee, EmployeeStatus::Active);

        return redirect()->route('employees.show', $employee)->with('status', 'Employee activated.');
    }

    public function deactivate(Request $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $employee, EmployeeStatus::Inactive);

        return redirect()->route('employees.show', $employee)->with('status', 'Employee deactivated.');
    }

    public function photo(Employee $employee): StreamedResponse
    {
        Gate::authorize('view', $employee);
        abort_unless($employee->photo_path && Storage::disk('local')->exists($employee->photo_path), 404);

        return Storage::disk('local')->response($employee->photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
