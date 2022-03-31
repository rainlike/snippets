<?php

public static function updateBlockType(array $data, BlockTypeBasicForm $form): Notice
{
    $transaction = DbHelper::getTransaction();

    try {
        $transformedData = self::transformData($data, $form, true);

        /** Валидация и обновление блока */
        /** @var SuperPortalBlock|Notice $blockModel */
        $blockModel = BlockService::updateBlock(
            $form->getBlock(),
            $transformedData['block']
        );
        if ($blockModel instanceof Notice) {
            return $blockModel;
        }

        /** Валидация и обновление айтемов */
        $isItemsSaved = self::updateItems(
            $form,
            $transformedData['items'],
            $blockModel->id
        );
        if ($isItemsSaved->isError()) {
            return $isItemsSaved;
        }

        /** Валидация и обновление дополнительных данных айтема */
        $additionalData = $transformedData['additional_data'] ?? null;
        if ($additionalData) {
            $isAdditionalDataSaved = self::saveAdditionalData($additionalData);
            if ($isAdditionalDataSaved->isError()) {
                return $isAdditionalDataSaved;
            }
        }

        $transaction->commit();
        return NoticeGenerator::generateUpdateSuccessMessage(Notice::ENTITY_BLOCK);
    } catch (\Exception $e) {
        $transaction->rollBack();
        return NoticeGenerator::generateUpdateErrorMessage(Notice::ENTITY_BLOCK);
    }
}

public function actionCreate()
{
    $locales = \Yii::$app->request->isPost
        ? array_keys(\Yii::$app->request->post('TitleForm'))
        : [Dictionary::LOCALE_UK_UA];

    $form = new CreateForm([], $locales);

    /** Дозагрузка данных _POST */
    $postData = $this->getPost();
    $form->load($postData);
    $form->loadTranslations($postData);

    if ($this->isAjax()) {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        return ActiveForm::validate($form);
    }

    if ($this->isPost()) {
        $pageService = new PageService($form);
        /** @var Successor $isSaved */
        $isSaved = $pageService->create();

        if (!$isSaved->isSuccess()) {
            $this->setFlashMessage(
                $isSaved->getNotice()->getStatus(),
                $isSaved->getNotice()->getMessage()
            );
        } else {
            return $this->redirectWithFlashMessage(
                $isSaved->getNotice()->getStatus(),
                $isSaved->getNotice()->getMessage(),
                self::ACTION_UPDATE,
                ['id' => $pageService->getTargetModel()->id]
            );
        }
    }

    $breadcrumbs = (new Breadcrumbs([
        ['label' => 'Кастомные Страницы', 'url' => '/index']
    ], 'page'))->getBreadcrumbs();

    return $this->render(self::ACTION_CREATE, [
        'form' => $form,
        'breadcrumbs'  => $breadcrumbs
    ]);
}

private function getTileConfig(array $mpath, string $type): array
{
    if (!\in_array($type, [self::PRODUCT_MARK, self::CATEGORY_MARK])) {
        return [];
    }

    $tileConfigPathFunc = $type.'TileConfigPath';
    $tileConfigPath = $this->pathsComponent->$tileConfigPathFunc();
    $path = "{$tileConfigPath}/default.json";

    $data = \file_get_contents($path);
    $tileConfigDefault = $data ? (\json_decode($data, true) ?? []) : [];

    $priorities = \array_reverse($mpath);
    $tileConfigCustom = [];

    foreach ($priorities as $priority) {
        $path = "{$tileConfigPath}/{$priority}.json";
        if (\file_exists($path)) {
            $data = \file_get_contents($path);
            $tileConfigCustom += $data ? (\json_decode($data, true) ?? []) : [];
        }
    }

    return \array_replace_recursive($tileConfigDefault, $tileConfigCustom);
}

public function actionGetCanonical()
{
    $categoryId = \abs((int)\Yii::$app->request->get('category_id'));
    $canonicalPages = [];

    if (!CountryHelper::isUzCountry()) {
        $activeCanonicals = CanonicalPagesModel::getActiveCanonicals();

        foreach ($activeCanonicals as $canonicalObj) {
            $needle = "c{$categoryId}";
            if (\strpos($canonicalObj->page, $needle)) {
                $canonicalPages[] = [
                    'page' => $canonicalObj->page,
                    'canonical_page' => $canonicalObj->canonical_page,
                ];
            }
        }
    }

    return ['data' => $canonicalPages];
}

public function buildAndCondition($indexes, $operator, $operands, &$params)
{
    $parts = [];
    foreach ($operands as $operand) {
        if (\is_array($operand) || $operand instanceof Expression) {
            $operand = $this->buildCondition($indexes, $operand, $params);
        }

        if ($operand !== '') {
            $parts[] = $operand;
        }
    }

    if (!empty($parts)) {
        $withMatch = \strpos($parts[0], "MATCH") === 0;
        if ($withMatch) {
            $matchPart = $parts[0];
            \array_shift($parts);

            return "$matchPart $operator " . \implode(") $operator (", $parts);
        }

        return '(' . \implode(") $operator (", $parts) . ')';
    }

    return '';
}










