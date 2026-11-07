<?php

namespace App\Http\Controllers;

use App\Organization\Company;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationAdminController extends Controller
{
    /** @var array<string, class-string<Department|Position|Location>> */
    private const TYPES = ['departments' => Department::class, 'positions' => Position::class, 'locations' => Location::class];

    private function model(string $type): string
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    private function authorize(Request $request): void
    {
        Gate::authorize('manage-staff', Company::findOrFail($request->user()->company_id));
    }

    public function index(Request $request, string $type): View
    {
        $this->authorize($request);
        $model = $this->model($type);
        $search = trim((string) $request->query('search', ''));

        return view('admin.organization', [
            'type' => $type,
            'search' => $search,
            'items' => $model::query()->where('company_id', $request->user()->company_id)
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderBy('name')->paginate(20)->withQueryString(),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $this->authorize($request);
        $model = $this->model($type);
        $data = $this->validated($request, $type);
        $model::query()->create($data + ['company_id' => $request->user()->company_id]);

        return redirect()->route('admin.organization.index', $type)->with('status', __('admin.saved'));
    }

    public function update(Request $request, string $type, int $item): RedirectResponse
    {
        $this->authorize($request);
        $model = $this->model($type);
        $record = $model::query()->where('company_id', $request->user()->company_id)->findOrFail($item);
        $record->update($this->validated($request, $type, $item));

        return redirect()->route('admin.organization.index', $type)->with('status', __('admin.saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $type, ?int $item = null): array
    {
        $model = $this->model($type);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique($model, 'name')->where('company_id', $request->user()->company_id)->ignore($item)],
            'is_active' => ['required', 'boolean'],
            'timezone' => $type === 'locations' ? ['required', 'timezone:all'] : ['prohibited'],
        ]);
    }
}
