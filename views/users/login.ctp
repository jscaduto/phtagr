<?php echo $session->flash(); ?>

<?php echo $form->create('User', array('action' => 'login', 'class' => 'login')); ?>
<fieldset>
<legend><?php __('Login'); ?></legend>
<?php
  echo $form->input('User.username', array('label' => __('Username', true)));
  echo $form->input('User.password', array('label' => __('Password', true)));
?>
</fieldset>
<?php 
  $signup = '';
  echo $form->submit(__('Login', true));
  echo $html->link(__('Forgot your password', true), 'password');
  if ($register) {
    echo $html->link(__('Sign Up', true), 'register');
  }
  echo $form->end();
  $script = <<<'JS'
(function($) {
  $(document).ready(function() {
    $(':submit').button();
    $('.message').addClass("ui-widget ui-corner-all ui-state-highlight");
  });
})(jQuery);
JS;
  echo $this->Html->scriptBlock($script, array('inline' => false));
?>
