<?php

use App\Mail\OTPMail;

test('the OTP email renders the code and how long it lasts', function () {
    config(['service-contract.auth.otp_expired' => 15]);

    $mail = new OTPMail('482913');

    $mail->assertHasSubject('Kode Verifikasi Anda');
    $html = $mail->render();

    expect($html)->toContain('482913')->toContain('15 menit');
});
