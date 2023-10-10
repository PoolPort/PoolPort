<html>
    <body>
        <script>
        	var form = document.createElement("form");
        	form.setAttribute("method", "POST");
        	form.setAttribute("action", "https://pna.shaparak.ir/_ipgw_/payment/");
        	form.setAttribute("target", "_self");

            var hiddenField = document.createElement("input");
        	hiddenField.setAttribute("name", "token");
        	hiddenField.setAttribute("value", "<?php echo $token ?>");

            var hiddenField2 = document.createElement("input");
        	hiddenField2.setAttribute("name", "language");
        	hiddenField2.setAttribute("value", "fa");

            form.appendChild(hiddenField);
            form.appendChild(hiddenField2);

        	document.body.appendChild(form);
        	form.submit();
        	document.body.removeChild(form);
        </script>
    </body>
</html>
