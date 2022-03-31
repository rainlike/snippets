<?php

public function send(
    SendRequest $request,
    VacancyService $vacancyService,
    NotificationService $notificationService
): JsonResponse {
    $data = $request->validated();

    if ($request->has('vacancy_id')) {
        $vacancy = $vacancyService->getById((int)$data['vacancy_id'], $request->country);
        $response = $notificationService->sendVacancyMail($vacancy, $data);
        $notificationService->log($request, $response);

        return new JsonResponse([
            'messages' => [__('custom.notification.success_response')],
            'errors' => [],
        ]);
    }

    $response = $notificationService->sendGeneralMail($data);
    $notificationService->log($request, $response);

    return new JsonResponse([
        'messages' => [__('custom.notification.success_response')],
        'errors' => [],
    ]);
}

public function getAvailableFilters($relations = [], string $locale = LocaleService::LOCALE_RU): array
{
    $filters = [
        Filter::make('department')->forRelations($relations)->toArray(),
        Filter::make('division')->forRelations($relations)->toArray(),
        Filter::make('city')->forRelations($relations)->toArray(),
    ];

    $checkboxes = ['is_partial', 'is_remote', 'is_night_shift', 'is_inexperienced'];
    foreach ($checkboxes as $checkbox) {
        $filter = Vacancy::query()
            ->when(
                isset($relations['vacancies']),
                fn (Builder $builder) => $builder->whereIn('id', $relations['vacancies'])
            )
            ->where($checkbox, true)
            ->exists()
        ;

        if ($filter) {
            $filters[] = Filter::make($checkbox)->forRelations($relations)->toArray();
        }
    }

    return $filters;
}

public function show(Vacancy $vacancy): JsonResponse
{
    try {
        $vacancy->load(['addresses']);
        $locationData = $this->addressService->getDataByAddresses($vacancy->addresses);
        $vacancy->addresses->each(function (Address $address) use ($locationData): void {
            $address->locationData = [
                'country' => $locationData['countries'][$address->mdm_country_id] ?? null,
                'settlement' => $locationData['settlements'][$address->mdm_settlement_id] ?? null,
                'pickup' => $locationData['pickups'][$address->mdm_pickup_id] ?? null,
            ];
        });
    } catch (Exception $exception) {
        Log::channel('module')->error($exception->getMessage());
        $error = __('exceptions.notification.common');
    }

    return new JsonResponse(VacancyResource::make($vacancy), Response::HTTP_OK, $error ?? null);
}

public function addTitleAndSlugEqual(Builder $builder, $value): void
{
    $builder
        ->where(function (Builder $builder) use ($value): void {
            $builder
                ->where('title', 'ilike', "%{$value}%")
                ->when(is_numeric($value), fn (Builder $b) => $b->orWhere('id', (int)$value))
                ->orWhere('slug', 'ilike', "%{$value}%")
                ->orWhereHas('translations', function (Builder $builder) use ($value): void {
                    $builder->where('field', 'title')
                        ->whereIn('locale', [LocaleService::LOCALE_UA, LocaleService::LOCALE_UZ])
                        ->where('content', 'ilike', "%{$value}%")
                    ;
                })
            ;
        })
    ;
}

public function findHotVacancies(Collection $departments, int $count, string $country, string $locale): Collection
{
    $query = Vacancy::with([
        'department.translations' => fn (MorphMany $builder) => $builder->where('locale', $locale),
        'division.translations' => fn (MorphMany $builder) => $builder->where('locale', $locale),
        'manager',
        'addresses',
        'translations' => fn (MorphMany $builder) => $builder->where('locale', $locale),
    ])
        ->active($country)
        ->where('is_hot', true)
        ->whereHas('department', function (Builder $builder) use ($departments): void {
            $builder->whereIn('department_id', $departments->pluck('id'));
        })
        ->orderBy('updated_date', 'desc')
        ->orderBy('id', 'desc')
    ;

    if ($count) {
        $query->limit($count);
    }

    return $query->get();
}
