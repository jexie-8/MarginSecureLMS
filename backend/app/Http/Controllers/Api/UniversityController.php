<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UniversityResource;
use App\Models\University;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UniversityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', University::class);
        $query = University::query()->orderBy('name')->orderBy('id');
        if (! $request->user()->isPlatformAdmin()) {
            $query->whereKey($request->user()->university_id);
        }

        return UniversityResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): UniversityResource
    {
        Gate::authorize('create', University::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:universities,code'],
        ]);
        $university = University::query()->create($data);
        $audit->record($request->user(), 'university.created', $university, [], $request);

        return new UniversityResource($university);
    }

    public function show(Request $request, University $university): UniversityResource
    {
        $this->ensureUniversityVisible($request->user(), $university->id);
        Gate::authorize('view', $university);

        return new UniversityResource($university);
    }

    public function update(Request $request, University $university, AuditLogger $audit): UniversityResource
    {
        Gate::authorize('update', $university);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('universities')->ignore($university)],
        ]);
        $university->update($data);
        $audit->record($request->user(), 'university.updated', $university, ['changed' => array_keys($data)], $request);

        return new UniversityResource($university);
    }
}
