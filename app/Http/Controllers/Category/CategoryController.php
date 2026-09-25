<?php

namespace App\Http\Controllers\Category;

use App\Contract\Category\CategoryContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Utils\ListFilter;
use App\Utils\WebResponse;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    public function __construct(protected CategoryContract $service) {}

    public function index()
    {
        return Inertia::render('category/index');
    }

    public function fetch()
    {
        $data = $this->service->all(
            allowedFilters: [ListFilter::search(['name'])],
            allowedSorts: [],
            withPaginate: true,
            perPage: request()->get('per_page', 10),
            orderColumn: 'sort_order',
        );

        return response()->json($data);
    }

    public function create()
    {
        return Inertia::render('category/form');
    }

    public function store(CategoryRequest $request)
    {
        $data = $this->service->create($request->validated());

        return WebResponse::response($data, 'backoffice.category.index');
    }

    public function show($id)
    {
        $category = $this->service->find($id);

        if ($category instanceof Exception) {
            abort(404);
        }

        return Inertia::render('category/form', [
            'category' => $category,
        ]);
    }

    public function update(CategoryRequest $request, $id)
    {
        $data = $this->service->update($id, $request->validated());

        return WebResponse::response($data, 'backoffice.category.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);

        return WebResponse::response($data, 'backoffice.category.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);

        return WebResponse::response($data, 'backoffice.category.index');
    }
}
