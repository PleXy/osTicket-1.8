<?php

$info=array();
if($form && $_REQUEST['a']!='add') {
    $title = 'Update dynamic form';
    $action = 'update';
    $submit_text='Save Changes';
    $info = $form->ht;
    $newcount=2;
} else {
    $title = 'Add new dynamic form';
    $action = 'add';
    $submit_text='Add Form';
    $newcount=4;
}
$info=Format::htmlchars(($errors && $_POST)?$_POST:$info);

?>
<form action="?" method="post" id="save">
    <?php csrf_token(); ?>
    <input type="hidden" name="do" value="<?php echo $action; ?>">
    <input type="hidden" name="id" value="<?php echo $info['id']; ?>">
    <h2>Dynamic Form</h2>
    <table class="form_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr>
            <th colspan="2">
                <h4><?php echo $title; ?></h4>
                <em>Dynamic forms are used to allow custom data to be
                associated with tickets</em>
            </th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td width="180" class="required">Title:</td>
            <td><input type="text" name="title" value="<?php echo $info['title']; ?>"/></td>
        </tr>
        <tr>
            <td width="180">Description:</td>
            <td><textarea name="notes" rows="3" cols="40"><?php
                echo $info['notes']; ?></textarea>
            </td>
        </tr>
    </tbody>
    </table>
    <table class="form_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr>
            <th colspan="6">
                <em>Form Fields</em>
            </th>
        </tr>
        <tr>
            <th>Delete</th>
            <th>Order</th>
            <th>Label</th>
            <th>Type</th>
            <th>Name</th>
            <th>Required</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($form) foreach ($form->getFields() as $f) { 
        $id = $f->get('id'); ?>
        <tr>
            <td><?php if ($f->get('editable')) { ?>
                <input type="checkbox" name="delete-<?php echo $id; ?>"/>
            <?php } ?></td>
            <td><input type="text" size="4" name="_sort-<?php echo $id; ?>"
                value="<?php echo $f->get('sort'); ?>"/></td>
            <td><input type="text" size="48" name="_label-<?php echo $id; ?>"
                value="<?php echo $f->get('label'); ?>"/></td>
            <td><select name="_type-<?php echo $id; ?>">
                <?php foreach (get_dynamic_field_types() as $type=>$nfo) { ?>
                <option value="<?php echo $type; ?>" <?php
                    if ($f->get('type') == $type) echo 'selected="selected"'; ?>>
                    <?php echo $nfo[0]; ?></option>
                <?php } ?>
            </select></td>
            <td><?php if ($f->get('editable')) { ?>
                <input type="text" size="24" name="_name-<?php echo $id; ?>"
                    value="<?php echo $f->get('name'); ?>"/>
                <?php } else echo $f->get('name'); ?></td>
            <td><input type="checkbox" name="required-<?php echo $id; ?>"
                <?php if (!$f->get('editable')) { ?>disabled="disabled" <?php }
                      if ($f->get('required')) echo 'checked="checked"'; ?>/></td>
        </tr>
    <?php
    } 
    for ($i=0; $i<$newcount; $i++) { ?>
            <td><em>add</em></td>
            <td><input type="text" size="4" name="sort-new-<?php echo $i; ?>"/></td>
            <td><input type="text" size="48" name="label-new-<?php echo $i; ?>"/></td>
            <td><select name="type-new-<?php echo $i; ?>">
                <?php foreach (get_dynamic_field_types() as $type=>$nfo) { ?>
                <option value="<?php echo $type; ?>"> 
                    <?php echo $nfo[0]; ?></option>
                <?php } ?>
            </select></td>
            <td><input type="text" size="24" name="name-<?php echo $id; ?>"/></td>
            <td><input type="checkbox" name="required-new-<?php echo $i; ?>"/></td>
        </tr>
    <?php } ?>
    </tbody>
    </table>
<p style="padding-left:225px;">
    <input type="submit" name="submit" value="<?php echo $submit_text; ?>">
    <input type="reset"  name="reset"  value="Reset">
    <input type="button" name="cancel" value="Cancel" onclick='window.location.href="?"'>
</p>
</form>
