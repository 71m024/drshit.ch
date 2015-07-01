jQuery(function ($) {
  $('.subdomain').submit(function (e) {
    $('.alert').fadeOut();
    e.preventDefault();
    $.post("/add", {
        subdomain: $('#subdomain').val(),
        url: $('#url').val()
      },
      function (response) {

        if (response[0] == 'error'){
          $('.subdomain-error').html(response[1]);
          $('.subdomain-error').fadeIn();
        }
        else {
          $('.subdomain-success').html(response[1]);
          $('.subdomain').slideUp();
          $('.subdomain-success').fadeIn();
        }
      }, "json"
    );

  });

});