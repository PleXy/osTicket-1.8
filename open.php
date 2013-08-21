<?php
/*********************************************************************
    open.php

    New tickets handle.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require('client.inc.php');
define('SOURCE','Web'); //Ticket source.
$ticket = null;
$errors=array();
if($_POST):
    $vars = $_POST;
    $vars['deptId']=$vars['emailId']=0; //Just Making sure we don't accept crap...only topicId is expected.
    if($thisclient) {
        $vars['name']=$thisclient->getName();
        $vars['email']=$thisclient->getEmail();
    } elseif($cfg->isCaptchaEnabled()) {
        if(!$_POST['captcha'])
            $errors['captcha']='Enter text shown on the image';
        elseif(strcmp($_SESSION['captcha'],md5($_POST['captcha'])))
            $errors['captcha']='Invalid - try again!';
    }

    $interest=array('name','email','subject');
    $topic=Topic::lookup($vars['topicId']);
    $forms=DynamicFormset::lookup($topic->ht['formset_id'])->getForms();
    foreach ($forms as $idx=>$f) {
        $form=$f->getForm()->instanciate($f->sort);
        # Collect name, email, and subject address for banning and such
        foreach ($form->getAnswers() as $answer) {
            $fname = $answer->getField()->get('name');
            if (in_array($fname, $interest))
                # XXX: Assigning to _POST not considered great PHP
                #      coding style
                $vars[$fname] = $answer->getField()->getClean();
        }
        $forms[$idx] = $form;
        if (!$form->isValid())
            $errors = array_merge($errors, $form->errors());
    }

    if(!$errors && $cfg->allowOnlineAttachments() && $_FILES['attachments'])
        $vars['files'] = AttachmentFile::format($_FILES['attachments'], true);

    //Ticket::create...checks for errors..
    if(($ticket=Ticket::create($vars, $errors, SOURCE))){
        $msg='Support ticket request created';
        # TODO: Save dynamic form(s)
        foreach ($forms as $f) {
            $f->set('ticket_id', $ticket->getId());
            $f->save();
        }
        $ticket->loadDynamicData();
        //Logged in...simply view the newly created ticket.
        if($thisclient && $thisclient->isValid()) {
            if(!$cfg->showRelatedTickets())
                $_SESSION['_client']['key']= $ticket->getExtId(); //Resetting login Key to the current ticket!
            session_write_close();
            session_regenerate_id();
            @header('Location: tickets.php?id='.$ticket->getExtId());
        }
    }else{
        $errors['err']=$errors['err']?$errors['err']:'Unable to create a ticket. Please correct errors below and try again!';
    }
endif;

//page
$nav->setActiveNav('new');
require(CLIENTINC_DIR.'header.inc.php');
if($ticket
        && (
            (($topic = $ticket->getTopic()) && ($page = $topic->getPage()))
            || ($page = $cfg->getThankYouPage())
            )) { //Thank the user and promise speedy resolution!
    //Hide ticket number -  it should only be delivered via email for security reasons.
    echo Format::safe_html($ticket->replaceVars(str_replace(
                    array('%{ticket.number}', '%{ticket.extId}', '%{ticket}'), //ticket number vars.
                    array_fill(0, 3, 'XXXXXX'),
                    $page->getBody()
                    )));
} else {
    require(CLIENTINC_DIR.'open.inc.php');
}
require(CLIENTINC_DIR.'footer.inc.php');
?>
