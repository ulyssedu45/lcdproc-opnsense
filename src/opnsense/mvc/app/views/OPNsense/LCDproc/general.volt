{#
    Copyright (C) 2024 OPNsense LCDproc Plugin
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
#}

<script>
    $( document ).ready(function() {
        var data_get_map = {'frm_general_settings':"/api/lcdproc/general/get"};
        mapDataToFormUI(data_get_map).done(function(data){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            updateServiceControlUI('lcdproc');
        });

        // Show/hide HD44780 connection type based on driver selection
        function toggleConnectionType() {
            var driver = $('#general\\.driver').val();
            if (driver === 'hd44780') {
                $('[id="row_general.connection_type"]').closest('tr').show();
            } else {
                $('[id="row_general.connection_type"]').closest('tr').hide();
            }
        }

        // Show/hide Matrix Orbital options
        function toggleMtxOrbOptions() {
            var driver = $('#general\\.driver').val();
            if (driver === 'MtxOrb') {
                $('[id="row_general.mtxorb_type"]').closest('tr').show();
                $('[id="row_general.mtxorb_adjustable_backlight"]').closest('tr').show();
                $('[id="row_general.mtxorb_backlight_color"]').closest('tr').show();
            } else {
                $('[id="row_general.mtxorb_type"]').closest('tr').hide();
                $('[id="row_general.mtxorb_adjustable_backlight"]').closest('tr').hide();
                $('[id="row_general.mtxorb_backlight_color"]').closest('tr').hide();
            }
        }

        // Show/hide Output LEDs option (CFontzPacket only)
        function toggleOutputLeds() {
            var driver = $('#general\\.driver').val();
            if (driver === 'CFontzPacket') {
                $('[id="row_general.outputleds"]').closest('tr').show();
            } else {
                $('[id="row_general.outputleds"]').closest('tr').hide();
            }
        }

        $(document).on('change', '#general\\.driver', function() {
            toggleConnectionType();
            toggleMtxOrbOptions();
            toggleOutputLeds();
        });

        // Initial toggle after form load
        setTimeout(function() {
            toggleConnectionType();
            toggleMtxOrbOptions();
            toggleOutputLeds();
        }, 500);

        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function() {
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/lcdproc/general/set", 'frm_general_settings',
                    function () { dfObj.resolve(); }, true,
                    function () { dfObj.reject(); }
                );
                return dfObj;
            },
            onAction: function(data, status) {
                updateServiceControlUI('lcdproc');
            }
        });
    });
</script>

<div class="content-box" style="padding-bottom: 1.5em;">
    {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_general_settings'])}}
</div>

{{ partial(
    'layout_partials/base_apply_button',
    {
        'data_endpoint': '/api/lcdproc/service/reconfigure',
        'data_service_widget': 'lcdproc',
        'data_change_message_content': lang._('Apply will reload the LCDproc service. Any unsaved changes will be lost.')
    }
) }}
