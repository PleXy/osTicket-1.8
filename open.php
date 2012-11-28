<?php
/*********************************************************************
    open.php

    New tickets handle.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2012 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require('client.inc.php');
require_once(INCLUDE_DIR.'class.dynamic_forms.php');

define('SOURCE','Web'); //Ticket source.
$inc='open.inc.php';    //default include.
$errors=array();
if($_POST):
    $_POST['deptId']=$_POST['emailId']=0; //Just Making sure we don't accept crap...only topicId is expected.
    if($thisclient) {
        $_POST['name']=$thisclient->getName();
        $_POST['email']=$thisclient->getEmail();
    } elseif($cfg->isCaptchaEnabled()) {
        if(!$_POST['captcha'])
            $errors['captcha']='Enter text shown on the image';
        elseif(strcmp($_SESSION['captcha'],md5($_POST['captcha'])))
            $errors['captcha']='Invalid - try again!';
    }
    $interest=array('name','email','subject');
    $topic=Topic::lookup($_POST['topicId']);
    $forms=DynamicFormGroup::lookup($topic->ht['form_group_id'])->getForms();
    foreach ($forms as $idx=>$f) {
        $form=$f->getForm()->instanciate();
        $form->set('sort', $f->get('sort'));
        # Collect name, email, and subject address for banning and such
        foreach ($form->getAnswers() as $answer) {
            $fname = $answer->getField()->get('name');
            if (in_array($fname, $interest) and !isset($_POST[$fname]))
                # XXX: Assigning to _POST not considered great PHP
                #      coding style
                $_POST[$fname] = $answer->getField()->getClean();
        }
        $forms[$idx] = $form;
        if (!$form->isValid())
            $errors = array_merge($errors, $form->errors());
    }

    //Ticket::create...checks for errors..
    if(($ticket=Ticket::create($_POST,$errors,SOURCE))){
        $msg='Support ticket request created';
        //Upload attachments...         
        if($cfg->allowOnlineAttachments()
                && $_FILES['attachments']
                && ($files=Format::files($_FILES['attachments']))) {
            $ost->validateFileUploads($files); //Validator sets errors - if any.
            $ticket->uploadAttachments($files, $ticket->getLastMsgId(), 'M');
        }
        foreach ($forms as $f) {
            $f->set('ticket_id', $ticket->getId());
            $f->save();
        }

        //Logged in...simply view the newly created ticket.
        if($thisclient && $thisclient->isValid()) {
            if(!$cfg->showRelatedTickets())
                $_SESSION['_client']['key']= $ticket->getExtId(); //Resetting login Key to the current ticket!
            session_write_close();
            session_regenerate_id();
            @header('Location: tickets.php?id='.$ticket->getExtId());
        }
        //Thank the user and promise speedy resolution!
        $inc='thankyou.inc.php';
    }else{
        $errors['err']=$errors['err']?$errors['err']:'Unable to create a ticket. Please correct errors below and try again!';
    }
endif;

//page
$nav->setActiveNav('new');
require(CLIENTINC_DIR.'header.inc.php');
require(CLIENTINC_DIR.$inc);
require(CLIENTINC_DIR.'footer.inc.php');
?>
