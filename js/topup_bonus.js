jQuery(function($) {
  $('#_wallet_settings_topup_bonus-topup_bonus_amount_type').change(function() {
    var bonus_amount_desc = $('.topup_bonus_amount .description');
    if (this.value == 'percent') {
      bonus_amount_desc.text(twtb_lang.topup_bonus_percent);
    } else if (this.value == 'fixed') {
      bonus_amount_desc.text(twtb_lang.topup_bonus_fixed);
    }
  });
});