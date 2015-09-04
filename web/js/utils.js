/* -----------------------------------------------------------------------------------------------
 * Utilities
 * -----------------------------------------------------------------------------------------------*/
/* global jQuery, _ */

// Prevent leaking into global scope
!(function(exports, doc, $, _) {

  // Presents a dismissable bootstrap alert
  exports.presentAlert = function(message, type, $container, needsContainer) {
    var message = message || 'default message',
        type = type || 'info',
        $container = $container || $('body'),
        needsContainer = _.isBoolean(needsContainer) ? needsContainer : true;

    var $alert =
      $('<div class="alert alert-'+_.escape(type)+' fade in' +
        ( needsContainer ? ' container' : '') + '" role="alert">' +
          '<button type="button" class="close" data-dismiss="alert">' +
            '<span aria-hidden="true">&times;</span><span class="sr-only">Close</span>' +
          '</button>' +
          _.escape(message) +
        '</div>');

    $container.prepend($alert);
  };


  // Given a form and the validation requirements, checks if the inputs are valid
  exports.validateForm = function($form, validationRequirements) {
    var result = true;
    $form.find('.has-error').removeClass('has-error');
    $form.find('.validation-error').remove();
    _.each(validationRequirements, function(requirements, selector) {
      var $element = $form.find(selector);
      var $formGroup = $element.parents('.form-group');
      var value = $element.val();
      if (_.has(requirements, 'maxLength')) {
        if (value.length > requirements.maxLength) {
          $formGroup.addClass('has-error');
          $formGroup.append(
            '<span class="help-block validation-error">The maximum length is ' +
            requirements.maxLength + '</span>'
          );
          result = false;
        }
      }
      if (_.has(requirements, 'required')) {
        if (!value) {
          $formGroup.addClass('has-error');
          $formGroup.append(
            '<span class="help-block validation-error">This field is required</span>'
          );
          result = false;
        }
      }
    });
    return result;
  };

  // Gets a querystring param by a key
  exports.getQueryStringParam = function (key) {
    key = key.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
    var regex = new RegExp("[\\?&]" + key.toLowerCase() + "=([^&#]*)"),
        results = regex.exec(location.search.toLowerCase());
    return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
}

  exports.ping=function(pid){$.ajax({type:'POST',url:'/',
  data:JSON.stringify({action:'sk_init',partner_id: pid,payload:{id:'adserverkit',l:'php',v:'1.0.0'}}),
  processData:false, contentType: 'application/json'});};


}(window, window.document, jQuery, _));
