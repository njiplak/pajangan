<?php

namespace App\Http\Controllers\Producer;

use App\Contract\Producer\ProducerContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProducerRequest;
use App\Models\Producer;
use App\Utils\WebResponse;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProducerController extends Controller
{
    public function __construct(protected ProducerContract $service) {}

    public function index()
    {
        return Inertia::render('producer/index');
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
        return Inertia::render('producer/form');
    }

    public function store(ProducerRequest $request)
    {
        $data = $this->service->create($request->validated());

        return WebResponse::response($data, 'backoffice.producer.index');
    }

    public function show($id)
    {
        $producer = $this->service->find($id);

        if ($producer instanceof Exception) {
            abort(404);
        }

        return Inertia::render('producer/form', [
            'producer' => $this->transform($producer),
        ]);
    }

    public function update(ProducerRequest $request, $id)
    {
        $producer = Producer::findOrFail($id);

        if ($request->filled('removed_photo')) {
            $producer->media()->whereIn('id', [$request->input('removed_photo')])->get()->each->delete();
        }

        $data = $this->service->update($id, $request->validated());

        return WebResponse::response($data, 'backoffice.producer.index');
    }

    public function destroy($id)
    {
        $data = $this->service->destroy($id);

        return WebResponse::response($data, 'backoffice.producer.index');
    }

    public function destroy_bulk(Request $request)
    {
        $data = $this->service->bulkDeleteByIds($request->ids ?? []);

        return WebResponse::response($data, 'backoffice.producer.index');
    }

    private function transform(Producer $producer): array
    {
        return array_merge($producer->toArray(), [
            'images' => $producer->getMedia('photo')->map(fn ($media) => [
                'id' => $media->id,
                'collection_name' => $media->collection_name,
                'file_name' => $media->file_name,
                'original_url' => $media->getUrl(),
            ])->values(),
        ]);
    }
}
