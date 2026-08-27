<?php

namespace App\Http\Controllers\Page;

use App\Contract\Page\PageContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\PageRequest;
use App\Utils\WebResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PageController extends Controller
{
    protected PageContract $service;

    public function __construct(PageContract $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return Inertia::render('page/index');
    }

    public function fetch()
    {
        $data = $this->service->all(
            allowedFilters: [],
            allowedSorts: [],
            withPaginate: true,
            perPage: request()->get('per_page', 10)
        );

        return response()->json($data);
    }

    public function create()
    {
        return Inertia::render('page/form');
    }

    public function store(PageRequest $request)
    {
        $data = $this->service->create($request->validated());

        return WebResponse::response($data, 'backoffice.page.index');
    }

    public function show($id)
    {
        $page = $this->service->find($id);

        return Inertia::render('page/form', [
            'page' => $page,
        ]);
    }

    public function update(PageRequest $request, $id)
    {
        $data = $this->service->update($id, $request->validated());

        return WebResponse::response($data, 'backoffice.page.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);

        return WebResponse::response($data, 'backoffice.page.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);

        return WebResponse::response($data, 'backoffice.page.index');
    }
}
