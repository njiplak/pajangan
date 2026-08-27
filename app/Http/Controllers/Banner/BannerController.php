<?php

namespace App\Http\Controllers\Banner;

use App\Contract\Banner\BannerContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\BannerRequest;
use App\Models\Banner;
use App\Utils\WebResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BannerController extends Controller
{
    protected BannerContract $service;

    public function __construct(BannerContract $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return Inertia::render('banner/index');
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
        return Inertia::render('banner/form');
    }

    public function store(BannerRequest $request)
    {
        $data = $this->service->create($request->validated());

        return WebResponse::response($data, 'backoffice.banner.index');
    }

    public function show($id)
    {
        $banner = $this->service->find($id);

        return Inertia::render('banner/form', [
            'banner' => $this->transform($banner),
        ]);
    }

    public function update(BannerRequest $request, $id)
    {
        $banner = Banner::findOrFail($id);

        if ($request->filled('removed_image')) {
            $banner->media()
                ->whereIn('id', [$request->input('removed_image')])
                ->get()
                ->each->delete();
        }

        $data = $this->service->update($id, $request->validated());

        return WebResponse::response($data, 'backoffice.banner.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);

        return WebResponse::response($data, 'backoffice.banner.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);

        return WebResponse::response($data, 'backoffice.banner.index');
    }

    private function transform(Banner $banner): array
    {
        return array_merge($banner->toArray(), [
            'images' => $banner->getMedia('image')->map(fn ($media) => [
                'id' => $media->id,
                'collection_name' => $media->collection_name,
                'file_name' => $media->file_name,
                'original_url' => $media->getUrl(),
            ])->values(),
        ]);
    }
}
