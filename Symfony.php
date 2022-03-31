<?php

public function getAction(
    Request $request,
    CustomPageService $service,
    GetResource $resource
): Response {
    try {
        $valueObject = new ServiceVO(
            $request->getLocale(),
            $request->query->get(Country::NAME),
            $request->query->get(Uri::NAME),
            null,
            $request->query->get(Preview::NAME)
        );

        $service
            ->setPropertiesStack($valueObject)
            ->setResource($resource)
        ;

        $page = $service->getRepresented();

        $response = $service->makeResponse($page);

        return new Response($response, HttpResponse::HTTP_OK);
    } catch (Exception $e) {
        return new Response($e->getMessage(), $e->getCode());
    }
}

public function handle(): void
{
    $request = $this->requestStack->getCurrentRequest();

    $path = $request->getPathInfo();
    $action = preg_replace('/\/api\/v(\d+)\//i', '', $path);
    $handlerName = Library::kebabToCamelCase(str_replace('/', '-', $action), true);

    $handlerLiteral = self::ACTION_HANDLER_PREFIX.'\\'.$handlerName;
    if (class_exists($handlerLiteral)) {
        /** @var BaseActionHandler $handler */
        $handler = new $handlerLiteral(
            $this->requestStack,
            $this->container,
            $this->logger,
            $this->translator
        );

        $handler->handle();
    }
}

public function __construct(array $data)
{
    if (!Library::isAssociativeArray($data)) {
        throw new ObjectFillingException();
    }

    $properties = get_class_vars(static::class);
    $materialProperties = get_object_vars($this);
    $necessaryProperties = array_diff(array_keys($properties), array_keys($materialProperties));

    foreach ($properties as $property => $value) {
        $dataKey = Library::camelToSnakeCase($property);
        if (\array_key_exists($dataKey, $data)) {
            if (\in_array($property, $necessaryProperties, true) && $data[$dataKey] === null) {
                throw new ObjectFillingException();
            }

            $this->{$property} = $data[$dataKey];
        }

        try {
            $this->{$property};
        } catch (Error $e) {
            throw new ObjectFillingException();
        }
    }
}
