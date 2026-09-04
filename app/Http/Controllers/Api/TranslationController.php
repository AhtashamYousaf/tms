<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTranslationRequest;
use App\Http\Requests\UpdateTranslationRequest;
use App\Http\Resources\TranslationResource;
use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class TranslationController extends Controller
{
    public function __construct(private readonly TranslationService $translations) {}

    #[OA\Get(
        path: '/api/translations',
        summary: 'List and search translations',
        security: [['bearerAuth' => []]],
        tags: ['Translations'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'content', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'locale', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tag', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated translation list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['key', 'content', 'locale', 'tag']);

        $translations = $this->translations->search($filters, (int) $request->integer('per_page', 15));

        return TranslationResource::collection($translations);
    }

    #[OA\Post(
        path: '/api/translations',
        summary: 'Create a translation',
        security: [['bearerAuth' => []]],
        tags: ['Translations'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['key', 'locale', 'content'],
                properties: [
                    new OA\Property(property: 'key', type: 'string', example: 'welcome.message'),
                    new OA\Property(property: 'locale', type: 'string', example: 'en'),
                    new OA\Property(property: 'content', type: 'string', example: 'Welcome'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Translation created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreTranslationRequest $request): JsonResponse
    {
        $translation = $this->translations->create($request->validated());

        return TranslationResource::make($translation)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/translations/{translation}',
        summary: 'Retrieve a single translation',
        security: [['bearerAuth' => []]],
        tags: ['Translations'],
        parameters: [new OA\Parameter(name: 'translation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Translation found'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Translation $translation): TranslationResource
    {
        return TranslationResource::make($translation->load(['locale:id,code', 'tags:id,name']));
    }

    #[OA\Put(
        path: '/api/translations/{translation}',
        summary: 'Update a translation',
        security: [['bearerAuth' => []]],
        tags: ['Translations'],
        parameters: [new OA\Parameter(name: 'translation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'key', type: 'string'),
                    new OA\Property(property: 'locale', type: 'string'),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Translation updated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateTranslationRequest $request, Translation $translation): TranslationResource
    {
        $translation = $this->translations->update($translation, $request->validated());

        return TranslationResource::make($translation);
    }

    #[OA\Delete(
        path: '/api/translations/{translation}',
        summary: 'Delete a translation',
        security: [['bearerAuth' => []]],
        tags: ['Translations'],
        parameters: [new OA\Parameter(name: 'translation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Translation deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(Translation $translation): Response
    {
        $this->translations->delete($translation);

        return response()->noContent();
    }

    #[OA\Get(
        path: '/api/translations/export',
        summary: 'Export all translations as a locale => key => content JSON map',
        security: [['bearerAuth' => []]],
        tags: ['Translations'],
        parameters: [
            new OA\Parameter(name: 'locale', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tag', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Export payload')],
    )]
    public function export(Request $request): Response
    {
        $json = $this->translations->export(
            $request->string('locale')->value() ?: null,
            $request->string('tag')->value() ?: null,
        );

        return response($json, Response::HTTP_OK)
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=30, must-revalidate')
            ->setEtag(md5($json));
    }
}
