<?php

class GridFieldBulkActionTrueEditHandler extends GridFieldBulkActionHandler
{
    private DataObject $singleton;
    private string $oneLevelUpLink;

    /**
     * RequestHandler allowed actions.
     * @var array
     */
    private static $allowed_actions = [
        'index',
        'bulkEditForm',
    ];

    /**
     * RequestHandler url => action map.
     * @var array
     */
    private static $url_handlers = [
        'trueEdit/bulkEditForm' => 'bulkEditForm',
        'trueEdit' => 'index',
    ];

    /**
     * Return URL to this RequestHandler.
     * @param string $action Action to append to URL
     * @return string URL
     */
    public function Link($action = null): string
    {
        return Controller::join_links(parent::Link(), 'trueEdit', $action);
    }

    /**
     * Creates and return the bulk editing interface.
     * @return SS_HTTPResponse|ViewableData_Customised Form's HTML
     */
    public function index()
    {
        $form = $this->bulkEditForm();
        $form->setTemplate('LeftAndMain_EditForm');
        $form->addExtraClass('center cms-content');
        $form->setAttribute('data-pjax-fragment', 'CurrentForm Content');

        if ($this->request->isAjax()) {
            $response = new SS_HTTPResponse(
                Convert::raw2json(['Content' => $form->forAjaxTemplate()->getValue()])
            );
            $response->addHeader('X-Pjax', 'Content');
            $response->addHeader('Content-Type', 'text/json');
            $response->addHeader('X-Title', 'SilverStripe - Bulk '.$this->gridField->list->dataClass.' Editing');

            return $response;
        }

        return $this->getToplevelController()->customise(['Content' => $form]);
    }

    /**
     * Return a form for all the selected DataObjects
     * with their respective editable fields.
     * @return Form Selected DataObjects editable fields
     */
    public function bulkEditForm(): Form
    {
        $crumbs = $this->Breadcrumbs();
        if ($crumbs && $crumbs->count() >= 2) {
            $oneLevelUpLink = strtok($crumbs->offsetGet($crumbs->count() - 2)->Link, '?');
            $this->oneLevelUpLink = $oneLevelUpLink;
        }

        $actions = new FieldList();

        $actions->push(
            FormAction::create('doSave', 'Save (as multiple writes, including onAfterWrite logic etc.)')
                ->setAttribute('id', 'bulkEditingSaveBtn')
                ->addExtraClass('ss-ui-action-constructive')
                ->setAttribute('data-icon', 'accept')
                ->setUseButtonTag(true)
        );

        $actions->push(
            FormAction::create('doSave2', 'Save (as one UPDATE, no onAfterWrite logic etc.)')
                ->setAttribute('id', 'bulkEditingSaveBtn2')
                ->addExtraClass('ss-ui-action-constructive')
                ->setAttribute('data-icon', 'accept')
                ->setUseButtonTag(true)
        );

        if (isset($oneLevelUpLink)) {
            $actions->push(
                FormAction::create('Cancel', 'Cancel')
                    ->setAttribute('id', 'bulkEditingUpdateCancelBtn')
                    ->addExtraClass('ss-ui-action-destructive cms-panel-link')
                    ->setAttribute('data-icon', 'decline')
                    ->setAttribute('href', strtok($oneLevelUpLink, '?'))
                    ->setUseButtonTag(true)
                    ->setAttribute('src', '') // Changes type to image so isn't hooked by default actions handlers.
            );
        }

        $recordList = $this->getRecordIDList();
        $editingCount = count($recordList);

        $modelClass = $this->gridField->getModelClass();
        /** @var DataObject $singleton */
        $singleton = singleton($modelClass);
        $this->singleton = $singleton;

        $mainFieldList = new FieldList();

        $titleModelClass = $editingCount === 1 ? $singleton->i18n_singular_name() : $singleton->i18n_plural_name();
        $headerText = "Editing {$editingCount} {$titleModelClass}";
        $header = LiteralField::create(
            'bulkEditHeader',
            "<h1 style='font-size: 2em; font-weight: bold; margin: 1em 0;'>{$headerText}</h1>"
        );
        $mainFieldList->push($header);

        $singletonFields = $singleton->scaffoldFormFields();
        $unchangedFieldList = new FieldList();

        /** @var FormField $field */
        foreach ($singletonFields as $field) {
            $mainFieldList->push($field);
            $unchangedFieldList->push(
                CheckboxField::create($field->getName() . '_UnchangedCheckbox', $field->getName(), false)
            );
        }

        $mainFieldList->push(
            ToggleCompositeField::create(
                'UnchangedCheckboxes',
                'By default, only changes to the above fields are used to update the objects.
                If you want to use the value of the field no matter what, check box(es) below.',
                $unchangedFieldList
            )
        );

        $bulkEditForm = Form::create(
            $this,
            // `recordEditForm` name is here to trick SS to pass all subform request to `recordEditForm` method.
            'recordEditForm',
            $mainFieldList,
            $actions
        );

        if (isset($oneLevelUpLink)) {
            $bulkEditForm->Backlink = $oneLevelUpLink;
        }

        // Override form action URL back to bulkEditForm and add record ids GET var.
        $bulkEditForm->setAttribute(
            'action',
            $this->Link('bulkEditForm?records[]=' . implode('&records[]=', $recordList))
        );

        return $bulkEditForm;
    }

    /**
     * Call the below, setting $asRawUpdate to true.
     * @param array  $data    Submitted form data.
     * @param Form   $form    Form
     * @param object $someObj IDK what this is, but we don't use it.
     * @return string
     * @throws ValidationException
     */
    public function doSave2(array $data, Form $form, object $someObj): string
    {
        return $this->doSave($data, $form, $someObj, true);
    }

    /**
     * Handles bulkEditForm submission
     * and parses and saves each records data.
     * @param array        $data        Submitted form data.
     * @param Form         $form        Form
     * @param object       $someObj     IDK what this is, but we don't use it.
     * @param boolean|null $asRawUpdate Set this to true to perform the save as one UPDATE sql query. Faster but BEWARE
     *                                  that onAfterWrite (etc.) logic will NOT be called.
     * @return string
     * @throws ValidationException
     */
    public function doSave(array $data, Form $form, object $someObj, ?bool $asRawUpdate = false): string
    {
        $form->saveInto($this->singleton);
        $changes = $this->singleton->getChangedFields(true, DataObject::CHANGE_VALUE);

        $modelClass = $this->gridField->getModelClass();

        $writes = 0;

        $forceUnchangedFields = array_values(
            array_filter(
                array_keys($data),
                static fn($k) => preg_match('/_UnchangedCheckbox$/', $k)
            )
        );

        $fields = [];
        foreach ($changes as $field => $change) {
            if ($this->singleton->hasDatabaseField($field)) {
                $fields[$field] = $change['after'];
            }
        }
        foreach ($forceUnchangedFields as $field) {
            $field = strtok($field, '_');
            if ($this->singleton->hasDatabaseField($field)) {
                $fields[$field] = $data[$field] ?? null;
            }
        }

        if ($asRawUpdate) {
            $idsString = implode(',', $data['records']);

            DB::manipulate([
                $modelClass => [
                    'command' => 'update',
                    'fields' => $fields,
                    'where' => "ID IN ($idsString)"
                ]
            ]);

            $writes = DB::affected_rows();
        } else {
            foreach ($data['records'] as $id) {
                $record = DataObject::get_by_id($modelClass, $id);
                if ($record) {
                    foreach ($fields as $field => $value) {
                        $record->$field = $value;
                    }

                    if ($record->isChanged()) {
                        $record->write();
                        $writes++;
                    }
                }
            }
        }

        $niceClass = $writes === 1 ? $this->singleton->i18n_singular_name() : $this->singleton->i18n_plural_name();
        $output = "<p>Done. Updated $writes $niceClass.</p>";

        if (isset($this->oneLevelUpLink)) {
            $output .= "<a href='/$this->oneLevelUpLink'>Go back</a>";
        }

        return $output;
    }
}
