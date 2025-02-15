<?php

    define('DEBUGGING', true); // if true, displays debug information (var_dump of xmls array)
    define('LIMIT', -1); // limit of the first querry (for testing purposes) (-1 means no limit)

    if (DEBUGGING) {
        // error reporting
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
    }


    // excluded from git for security reasons
    // mainly contains $dbconnect variable
    // !!! REQUIRES CLOSING !!!
    include_once '../pohoda_db.php';
    // queries for pohoda database (they are long, so they are in separate file)
    include_once 'queries.php';
    // includes all functions for working with xml invoices
    include_once 'xml_format.php';
    // includes utility functions
    include_once 'utils.php';

    $dbconnect->set_charset("utf8");

    $dateAssigned = false; // flag if post request is valid

    if (isset($_POST['date'])) {
        $dateAssigned = true;

        if ($_POST['date'] != "") {
            
            $date = DateTime::createFromFormat('Y-m', $_POST['date']);
            
            $month = $date->format('m');
            $year = $date->format('Y');
        }
        else{
            $dateAssigned = false;
        }
    }

    function Fill_Xml($year, $month){
        // returns array of user ids for given date

        IndexDb($GLOBALS['dbconnect']); // index database for faster queries

        $xmls = array(); // array of invoices
        $xmls_internal = array(); // array of internal invoices

        date_default_timezone_set('Europe/Prague');

        $CustIds = GetCustIds($year, $month, $GLOBALS['dbconnect']);

        foreach ($CustIds as $id) {
            $info = GetInvoiceInfoForUser($id, $GLOBALS['dbconnect']); // header
            $invoice_items = GetInvoiceItemsForUser($id, $year, $month, $GLOBALS['dbconnect']); // detail

            $myfboId = "CZ{$id}";

            $invoice_id = "{$year}-{$month}-{$id}"; // year-month-cust_id
            $varSym = $year.$month.$id; // variable symbol

            $created_d = date('Y-m-d'); // today
            $invoice_d = DateTime::createFromFormat('Y-m-d', "{$year}-{$month}-1")->format('Y-m-t'); // last day of month

            // array of ico and dic from pohoda_adresar (if there is more than one, take first one) ->
            // -> ico is index 0, dic is index 1
            $ico_dic = GetICO_DIC($myfboId, $GLOBALS['dbconnect']); // get ico & dic from pohoda_adresar

            $description = "Pilot training";

            $company_name = $info['Organization'];
            $full_name = $info['FirstName']. ' ' .$info['LastName'];
            $city = $info['City'];
            $address = $info['Address1'] . ' ' . $info['Address2'];
            $zip = $info['ZipCode'];
            $ico = $ico_dic[0];
            $vat = $ico_dic[1];

            $total_price = GetTotalPrice($id, $year, $month, $GLOBALS['dbconnect']); // gets total price for id (-2 is error)
            if ($total_price === false) {
                echo "Error: Total price for id {$id} is false<br>";
                continue;
            }

            $invoice_xml = RetrieveXml($invoice_id, $varSym, $created_d, $invoice_d, $invoice_d, $invoice_d, $invoice_d,
                                       $id, $description, $company_name, 
                                       $full_name, $city, $address, $zip, $ico, $vat, $invoice_items, 
                                       $total_price); // creates xml

            

            $internal_p_final = 0; // final price for internal invoice
            $internal_xml = ""; // internal invoice xml

            $internal_price = $total_price; // for clarity purposes
            $balance = $info['PrepayBalance']; // balance for client

            if ($balance < 0 && abs($balance) > $internal_price)
            {
                $internal_xml = "";
            }
            else if ($balance < 0) // if balance is negative, add it to price
            {
                $internal_p_final = $internal_price + $balance;

                $internal_xml = RetrieveInternalXml($year, $month, $id,
                                                    $created_d, $invoice_d, $invoice_d, $invoice_d,
                                                    $full_name, $city, $address, $internal_p_final); // creates internal xml
            }
            else
            {
                $internal_xml = RetrieveInternalXml($year, $month, $id,
                                                    $created_d, $invoice_d, $invoice_d, $invoice_d,
                                                    $full_name, $city, $address, $total_price); // creates internal xml
            }

            $xmls += array($id => $invoice_xml);
            $xmls_internal += array($id => $internal_xml);
        }

        $final = array(
            'invoices' => $xmls,
            'internal' => $xmls_internal
        );

        return $final;
    }

    function PrepareDownloads($year, $month, $xmls)
    {
        $dir_prefix = "{$year}-{$month}-";

        $dir = "invoices/raw/{$dir_prefix}invoices";
        $zip_dir = "invoices/zip/{$dir_prefix}invoices.zip";

        // overwrite existing directory
        if (file_exists($dir)) {
            rrmdir($dir);
        }
        // overwrite existing zip
        if (file_exists($zip_dir)) {
            unlink($zip_dir);
        }

        mkdir($dir, 0777, true);

        $invoices = $xmls['invoices'];
        $internal = $xmls['internal'];

        $zip = new ZipArchive;
        $zip->open($zip_dir, ZipArchive::CREATE);

        foreach ($invoices as $id => $xml){
            $file_name = "{$id}-invoice.xml";
            $file_path = "{$dir}".DIRECTORY_SEPARATOR."{$file_name}";

            $file = fopen($file_path, "w");
            fwrite($file, $xml);
            fclose($file);

            $zip->addFile($file_path, $file_name);
        }
        foreach ($internal as $id => $xml) {
            $file_name = "{$id}-internal.xml";
            $file_path = "{$dir}".DIRECTORY_SEPARATOR."{$file_name}";

            if ($xml != "") // if there is no internal invoice, don't create file
            {
                $file = fopen($file_path, "w");
                fwrite($file, $xml);
                fclose($file);
    
                $zip->addFile($file_path, $file_name);
            }
        }

        $zip->close();
    }
    
    function DisplayDebugInformation($xmls){
        echo '<br><hr>';
        echo '<textarea style=\'width: 100%; height: 500px;\'>';
        var_dump($xmls);
        echo '</textarea><hr><br><br>';
    }

?>

<!-- ------------------------- HTML ------------------------- -->

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pohoda</title>

        <style>
            @font-face{
                font-family: "Nanum Gothic";
                src: url("assets/fonts/NanumGothic-Regular.ttf");
            }

            body{
                margin:0;
                padding:0;

                font-family: "Nanum Gothic", sans-serif;

                margin-top: 5px;
                margin-left: 5px;
            }
            #load{
                width:100%;
                height:100%;
                position:fixed;
                z-index:9999;
                background:url("assets/loaders/Hourglass.gif") no-repeat center center rgba(0,0,0,0.25);

                visibility: hidden;
            }
        </style>
    </head>
    <body>
        <div id="load"></div>

        <?php
        echo (LIMIT == -1 ? "" : "<h1>RESPONSE LIMITED TO ".LIMIT." ROWS!</h1>");
        ?>

        <form method="POST" action="">
            <input type="month" name="date">
            <input type="submit" value="Odeslat" onclick="load()">
        </form>
        <br>
        <?php

        if ($dateAssigned) { // test if it is false post / `date` is not set
            $xmls = Fill_Xml($year, $month); // fill xmls array with invoices and internal invoices
            PrepareDownloads($year, $month, $xmls); // prepare zip and directory for download
            
            echo '<a href="invoices/zip/'.$year.'-'.$month.'-invoices.zip" download>Stáhnout faktury</a><br><br>';

            ((DEBUGGING == true) ? DisplayDebugInformation($xmls) : "");
        }
        else{
            echo "Žádný měsíc nebyl vybrán!";
        }

        ?>
        <script>
            if ( window.history.replaceState ) { // prevents false postbacks
                window.history.replaceState( null, null, window.location.href );
            }

            function load(){ // show loader (called by the submit in the form)
                document.getElementById("load").style.visibility = "visible";
            }
        </script>
    </body>
</html>

<?php

    $dbconnect->close();
?>