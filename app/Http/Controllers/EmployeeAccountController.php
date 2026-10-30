<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Http\Requests\EmployeeAccountRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccountLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmployeeAccountController extends Controller
{
    public function index(EmployeeAccountRequest $request, User $managed_user): View
    {
        $query = Employee::where('status', EmployeeStatus::Active->value)->where(function ($q) use ($managed_user) {
            $q->whereDoesntHave('account');
            if ($managed_user->employee_id) {
                $q->orWhereKey($managed_user->employee_id);
            }
        });
        if (($q = $request->validated('q')) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(fn ($s) => $s->where('employee_number', 'like', $p)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$p]));
        }

        return view('administration.users.employee', ['account' => $managed_user, 'currentEmployee' => $managed_user->employee, 'employees' => $query->orderBy('first_name')->orderBy('id')->paginateTable(20)->withQueryString(), 'q' => $request->validated('q')]);
    }

    public function update(EmployeeAccountRequest $request, User $managed_user, EmployeeAccountLinker $service): RedirectResponse
    {
        $id = $request->validated('employee_id');
        $service->link($request->user(), $managed_user, $id === null ? null : (int) $id);

        return redirect()->route('admin.users.employee', $managed_user)->with('status', 'Employee account link saved.');
    }
}
