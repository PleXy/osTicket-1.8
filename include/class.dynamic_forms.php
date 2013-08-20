<?php
/*********************************************************************
    class.dynamic_forms.php

    Forms models built on the VerySimpleModel paradigm. Allows for arbitrary
    data to be associated with tickets. Eventually this model can be
    extended to associate arbitrary data with registered clients and thread
    entries.

    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once(INCLUDE_DIR . 'class.orm.php');
require_once(INCLUDE_DIR . 'class.forms.php');

/**
 * Form template, used for designing the custom form and for entering custom
 * data for a ticket
 */
class DynamicFormSection extends VerySimpleModel {

    static $meta = array(
        'table' => DYNAMIC_FORM_SEC_TABLE,
        'ordering' => array('title'),
    );

    function getFields() {
        if (!$this->_fields) {
            $this->_fields = array();
            foreach (DynamicFormField::objects()->filter(array('section_id'=>$this->id)) as $f)
                $this->_fields[] = $f->getImpl();
        }
        return $this->_fields;
    }
    function getTitle() { return $this->get('title'); }
    function getInstructions() { return $this->get('instructions'); }

    function getForm() {
        $fields = $this->getFields();
        foreach ($fields as &$f)
            $f = $f->getField();
        return new Form($fields, $this->title, $this->instructions);
    }

    function instanciate() {
        return DynamicFormEntry::create(array(
            'section_id'=>$this->get('id'), 'sort'=>$this->get('sort')));
    }

    static function lookup($id) {
        return ($id && is_numeric($id)
            && ($r=parent::lookup(array('id'=>$id)))
            && $r->get('id')==$id)?$r:null;
    }

    function save() {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        return parent::save(true);
    }

    static function create($ht=false) {
        $inst = parent::create($ht);
        $inst->set('created', new SqlFunction('NOW'));
        return $inst;
    }
}

require_once(INCLUDE_DIR . "class.json.php");

class DynamicFormField extends VerySimpleModel {
    
    static $meta = array(
        'table' => DYNAMIC_FORM_FIELD_TABLE,
        'ordering' => array('sort'),
        'pk' => array('id'),
        'joins' => array(
            'form' => array(
                'type' => 'left',
                'constraint' => array('form_id' => 'DynamicFormSection.id'),
            ),
            'sla' => array('sla_id' => 'Sla.id'),
        ),
    );

    /**
     * getClean
     *
     * Validates and cleans inputs from POST request. This is performed on a
     * field instance, after a DynamicFormSet / DynamicFormSection is
     * submitted via POST, in order to kick off parsing and validation of
     * user-entered data.
     */
    function getClean() {
        $value = $this->getWidget()->value;
        $value = $this->parse($value);
        $this->validateEntry($value);
        return $value;
    }

    function errors() {
        if (!$this->_errors) return array();
        else return $this->_errors;
    }

    /**
     * isValid
     *
     * Validates the contents of $this->ht before the model should be
     * committed to the database. This is the validation for the field
     * template -- edited in the admin panel for a form section.
     */
    function isValid() {
        if (!is_numeric($this->get('sort')))
            $this->_errors['sort'] = 'Enter a number';
        if (strpos($this->get('name'), ' ') !== false)
            $this->_errors['name'] = 'Name cannot contain spaces';
        return count($this->errors()) === 0;
    }

    function getAnswer() { return $this->answer; }

    function getConfigurationOptions() {
        return array();
    }

    function getConfigurationForm() {
        if (!$this->_cform) {
            $types = get_dynamic_field_types();
            $clazz = $types[$this->get('type')][1];
            $T = new $clazz();
            $this->_cform = $T->getConfigurationOptions();
        }
        return $this->_cform;
    }

    /**
     * setConfiguration
     *
     * Used in the POST request of the configuration process. The
     * ::getConfigurationForm() method should be used to retrieve a
     * configuration form for this field. That form should be submitted via
     * a POST request, and this method should be called in that request. The
     * data from the POST request will be interpreted and will adjust the
     * configuration of this field
     *
     * Parameters:
     * errors - (OUT array) receives validation errors of the parsed
     *      configuration form
     *
     * Returns:
     * (bool) true if the configuration was updated, false if there were
     * errors. If false, the errors were written into the received errors
     * array.
     */
    function setConfiguration($errors) {
        $errors = $config = array();
        foreach ($this->getConfigurationForm() as $name=>$field) {
            $config[$name] = $field->getClean();
            $errors = array_merge($errors, $field->errors());
        }
        if (count($errors) === 0)
            $this->set('configuration', JsonDataEncoder::encode($config));
        $this->set('hint', $_POST['hint']);
        return count($errors) === 0;
    }

    /**
     * getConfiguration
     *
     * Loads configuration information from database into hashtable format.
     * Also, the defaults from ::getConfigurationOptions() are integrated
     * into the database-backed options, so that if options have not yet
     * been set or a new option has been added and not saved for this field,
     * the default value will be reflected in the returned configuration.
     */
    function getConfiguration() {
        if (!$this->_config) {
            $this->_config = $this->get('configuration');
            if (is_string($this->_config))
                $this->_config = JsonDataParser::parse($this->_config);
            elseif (!$this->_config)
                $this->_config = array();
            foreach ($this->getConfigurationOptions() as $name=>$field)
                if (!isset($this->_config[$name]))
                    $this->_config[$name] = $field->get('default');
        }
        return $this->_config;
    }

    function isConfigurable() {
        return true;
    }

    function delete() {
        // Don't really delete form fields as that will screw up the data
        // model. Instead, just drop the association with the form section
        // which will give the appearance of deletion. Not deleting means
        // that the field will continue to exist on form entries it may
        // already have answers on, but since it isn't associated with the
        // form section, it won't be available for new form submittals.
        $this->set('section_id', 0);
        $this->save();
    }

    function save() {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        return parent::save();
    }

    static function create($ht=false) {
        $inst = parent::create($ht);
        $inst->set('created', new SqlFunction('NOW'));
        return $inst;
    }
}

/**
 * Represents an entry to a dynamic form. Used to render the completed form
 * in reference to the attached ticket, etc. A form is used to represent the
 * template of enterable data. This represents the data entered into an
 * instance of that template.
 *
 * The data of the entry is called 'answers' in this model. This model
 * represents an instance of a form entry. The data / answers to that entry
 * are represented individually in the DynamicFormEntryAnswer model.
 */
class DynamicFormEntry extends VerySimpleModel {

    static $meta = array(
        'table' => DYNAMIC_FORM_ENTRY_TABLE,
        'ordering' => array('sort'),
        'joins' => array(
            'form' => array(
                'null' => true,
                'constraint' => array('form_id' => 'DynamicFormSection.id'),
            ),
        ),
    );

    function getAnswers() {
        if (!$this->_values) {
            $this->_values = DynamicFormEntryAnswer::objects()->filter(
                array('entry_id'=>$this->get('id')));
            foreach ($this->_values as $v)
                $v->entry = $this;
        }
        return $this->_values;
    }

    function getAnswer($name) {
        foreach ($this->getAnswers() as $ans)
            if ($ans->getField()->get('name') == $name)
                return $ans->getValue();
        return null;
    }

    function errors() {
        return $this->_errors;
    }

    function getTitle() { return $this->getForm()->getTitle(); }
    function getInstructions() { return $this->getForm()->getInstructions(); }

    function getForm() {
        if (!$this->_form)
            $this->_form = DynamicFormSection::lookup($this->get('section_id'));
        return $this->_form;
    }

    function getFields() {
        if (!$this->_fields) {
            $this->_fields = array();
            foreach ($this->getAnswers() as $a)
                $this->_fields[] = $a->getField();
        }
        return $this->_fields;
    }

    function isValid() {
        if (!is_array($this->_errors)) {
            $this->_errors = array();
            $this->getClean();
            foreach ($this->getFields() as $field)
                if ($field->errors())
                    $this->_errors[$field->get('id')] = $field->errors();
        }
        return !$this->_errors;
    }

    function getClean() {
        if (!$this->_clean) {
            $this->_clean = array();
            foreach ($this->getFields() as $field)
                $this->_clean[$field->get('id')] = $field->getClean();
        }
        return $this->_clean;
    }

    function forTicket($ticket_id) {
        return self::objects()->filter(array('ticket_id'=>$ticket_id));
    }

    /**
     * addMissingFields
     *
     * Adds fields that have been added to the linked form section (field
     * set) since this entry was originally created. If fields are added to
     * the form section, the method will automatically add the fields and
     * null answers to the entry.
     */
    function addMissingFields() {
        foreach ($this->getForm()->getFields() as $field) {
            $found = false;
            foreach ($this->getAnswers() as $answer) {
                if ($answer->get('field_id') == $field->get('id')) {
                    $found = true; break;
                }
            }
            if (!$found) {
                # Section ID is auto set in the ::save method
                $a = DynamicFormEntryAnswer::create(
                    array('field_id'=>$field->get('id')));
                $a->field = $field;
                // Add to list of answers
                $this->_values[] = $a;
            }
        }
    }

    function save() {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        parent::save('id');
        foreach ($this->getAnswers() as $a) {
            $a->set('value', $a->getField()->to_database($a->getField()->getClean()));
            $a->set('entry_id', $this->get('id'));
            $a->save();
        }
        $this->_values = array();
    }

    static function create($ht=false) {
        $inst = parent::create($ht);
        $inst->set('created', new SqlFunction('NOW'));
        foreach ($inst->getForm()->getFields() as $f) {
            $a = DynamicFormEntryAnswer::create(
                array('field_id'=>$f->get('id')));
            $a->field = $f;
            $inst->_values[] = $a;
        }
        return $inst;
    }
}

/**
 * Represents a single answer to a single field on a dynamic form section.
 * The data / answer to the field is linked back to the form section and
 * field which was originally used for the submission.
 */
class DynamicFormEntryAnswer extends VerySimpleModel {

    static $meta = array(
        'table' => DYNAMIC_FORM_FIELD_TABLE,
        'ordering' => array('field__sort'),
        'pk' => array('entry_id', 'field_id'),
        'joins' => array(
            'field' => array('field_id' => 'DynamicFormField.id'),
            'entry' => array('entry_id' => 'DynamicFormEntry.id'),
        ),
    );

    function getEntry() {
        return $this->entry;
    }

    function getForm() {
        if (!$this->form)
            $this->form = $this->getEntry()->getForm();
        return $this->form;
    }

    function getField() {
        if (!$this->field) {
            $this->field = DynamicFormField::lookup($this->get('field_id'))->getImpl();
            $this->field->answer = $this;
        }
        return $this->field;
    }

    function getJoins() {
        return array(
        );
    }

    function getValue() {
        if (!$this->_value)
            $this->_value = $this->getField()->to_php($this->get('value'));
        return $this->_value;
    }

    function toString() {
        return $this->getField()->toString($this->getValue());
    }
}

/**
 * A collection of form sections makes up a "form" in the context of dynamic
 * forms. This model represents that list of sections. The individual
 * association of form sections to this form are delegated to the
 * DynamicFormsetSections model
 */
class DynamicFormset extends VerySimpleModel {

    static $meta = array(
        'table' => DYNAMIC_FORMSET_TABLE,
        'ordering' => array('title'),
        'pk' => array('id'),
    );

    function getForms() {
        if (!$this->_forms)
            $this->_forms = DynamicFormsetSections::objects()->filter(
                    array('formset_id'=>$this->get('id')));
        return $this->_forms;
    }

    function hasField($name) {
        foreach ($this->getForms() as $form)
            foreach ($form->getForm()->getFields() as $f)
                if ($f->get('name') == $name)
                    return true;
    }

    function errors() {
        return $this->_errors;
    }

    function isValid() {
        if (!$this->_errors) $this->_errors = array();
        return count($this->_errors) === 0;
    }

    function save() {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        return parent::save();
    }

    static function create($ht=false) {
        $inst = parent::create($ht);
        $inst->set('created', new SqlFunction('NOW'));
        return $inst;
    }
}

/**
 * Represents an assocation of form section (DynamicFormSection) with a
 * "form" (DynamicFormset).
 */
class DynamicFormsetSections extends VerySimpleModel {
    static $meta = array(
        'table' => DYNAMIC_FORMSET_SEC_TABLE,
        'ordering' => array('sort'),
        'pk' => array('id'),
    );

    function getForm() {
        if (!$this->_section)
            $this->_section = DynamicFormSection::lookup($this->get('section_id'));
        return $this->_section;
    }

    function errors() {
        return $this->_errors;
    }

    function isValid() {
        if (!$this->_errors) $this->_errors = array();
        if (!is_numeric($this->get('sort')))
            $this->_errors['sort'] = 'Enter a number';
        return count($this->errors()) === 0;
    }
}

/**
 * Dynamic lists are used to represent list of arbitrary data that can be
 * used as dropdown or typeahead selections in dynamic forms. This model
 * defines a list. The individual items are stored in the DynamicListItem
 * model.
 */
class DynamicList extends VerySimpleModel {

    static $meta = array(
        'table' => DYNAMIC_LIST_TABLE,
        'ordering' => array('name'),
    );

    function getSortModes() {
        return array(
            'Alpha'     => 'Alphabetical',
            '-Alpha'    => 'Alphabetical (Reversed)',
            'SortCol'   => 'By Sort column'
        );
    }

    function getListOrderBy() {
        switch ($this->ht['sort_mode']) {
            case 'Alpha':   return 'value';
            case '-Alpha':  return '-value';
            case 'SortCol': return 'sort';
        }
    }

    function getPluralName() {
        if ($name = $this->get('plural_name'))
            return $name;
        else
            return $this->get('name') . 's';
    }

    function getItems($limit=false, $offset=false) {
        if (!$this->_items) {
            $this->_items = DynamicListItem::objects()->filter(
                    array('list_id'=>$this->get('id')))
                ->order_by($this->getListOrderBy())
                ->limit($limit);
        }
        return $this->_items;
    }

    function getItemCount() {
        return DynamicListItem::count(array('list_id'=>$this->get('id')));
    }

    function save() {
        if (count($this->dirty))
            $this->set('updated', new SqlFunction('NOW'));
        return parent::save();
    }

    static function create($ht=false) {
        $inst = parent::create(get_class(), $ht);
        $inst->set('created', new SqlFunction('NOW'));
        return $inst;
    }
}

/**
 * Represents a single item in a dynamic list
 *
 * Fields:
 * value - (char * 255) Actual list item content
 * extra - (char * 255) Other values that represent the same item in the
 *      list, such as an abbreviation. In practice, should be a
 *      space-separated list of tokens which should hit this list item in a
 *      search
 * sort - (int) If sorting by this field, represents the numeric sort order
 *      that this item should come in the dropdown list
 */
class DynamicListItem extends VerySimpleModel {

    static $meta = array(
        'table' => DYNAMIC_LIST_ITEM_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'list' => array(
                'null' => true,
                'constraint' => array('list_id' => 'DynamicList.id'),
            ),
        ),
    );

    function toString() {
        return $this->get('value');
    }

    function delete() {
        # Don't really delete, just unset the list_id to un-associate it with
        # the list
        $this->set('list_id', null);
        return $this->save();
    }
}

class SelectionField extends FormField {
    function getList() {
        if (!$this->_list) {
            $list_id = explode('-', $this->get('type'));
            $list_id = $list_id[1];
            $this->_list = DynamicList::lookup($list_id);
        }
        return $this->_list;
    }

    function getWidget() {
        return new SelectionWidget($this);
    }

    function parse($id) {
        return $this->to_php($id);
    }

    function to_php($id) {
        $item = DynamicListItem::lookup($id);
        # Attempt item lookup by name too
        if (!$item) {
            $item = DynamicListItem::objects->filter(array(
                        'value'=>$id,
                        'list_id'=>$this->getList()->get('id')));
            $item = (count($item)) ? $item[0] : null;
        }
        return $item;
    }

    function to_database($item) {
        if ($item && $item->get('id'))
            return $item->get('id');
        return null;
    }

    function toString($item) {
        return ($item) ? $item->toString() : '';
    }

    function getConfigurationOptions() {
        return array(
            'typeahead' => new ChoiceField(array(
                'id'=>1, 'label'=>'Widget', 'required'=>false,
                'default'=>false,
                'choices'=>array(false=>'Drop Down', true=>'Typeahead'),
                'hint'=>'Typeahead will work better for large lists')),
        );
    }
}

class SelectionWidget extends ChoicesWidget {
    function render() {
        $config = $this->field->getConfiguration();
        if (!$config['typeahead'])
            return parent::render();

        $source = array(); $value = false;
        foreach ($this->field->getList()->getItems() as $i)
            $source[] = array(
                'info' => $i->get('value'),
                'value' => strtolower($i->get('value').' '.$i->get('extra')),
                'id' => $i->get('id'));
        if ($this->value && get_class($this->value) == 'DynamicListItem') {
            // Loaded from database
            $value = $this->value->get('id');
            $name = $this->value->get('value');
        } else {
            // Loaded from POST
            $value = $this->value;
            $name = DynamicListItem::lookup($this->value);
            $name = ($name) ? $name->get('value') : null;
        }
        ?>
        <span style="display:inline-block">
        <input type="hidden" name="<?php echo $this->name; ?>"
            value="<?php echo $value; ?>" />
        <input type="text" size="30" id="<?php echo $this->name; ?>"
            value="<?php echo $name; ?>" />
        <script type="text/javascript">
        $(function() {
            $('#<?php echo $this->name; ?>').typeahead({
                source: <?php echo JsonDataEncoder::encode($source); ?>,
                onselect: function(item) {
                    $('#<?php echo $this->name; ?>').val(item['info'])
                    $('input[name="<?php echo $this->name; ?>"]').val(item['id'])
                }
            });
        });
        </script>
        </span>
        <?php
    }

    function getChoices() {
        if (!$this->_choices) {
            $this->_choices = array();
            foreach ($this->field->getList()->getItems() as $i)
                $this->_choices[$i->get('id')] = $i->get('value');
        }
        return $this->_choices;
    }
}

$a = DynamicFormSet::objects()->filter(array('name'=>'default'))
    ->filter(array('id__gt'=>3), array('id__lt'=>1))->order_by('name');

$start = microtime(true);
for ($i=0; $i<10000; $i++) {
    $b = DynamicListItem::objects()
        ->filter(array('list__name'=>'bubba'))
        ->values('id', 'list__id');
}
var_dump(microtime(true) - $start);

print($b);

?>
