<?php
$base = __DIR__;
$upload_dir = "$base/upload/";
$output_dir = "$base/output/";
$temp_dir = "$base/temp/";
$log_dir = "$base/logs/";
foreach([$upload_dir,$output_dir,$temp_dir,$log_dir] as $d) if(!is_dir($d)) mkdir($d, 0777, true);

if(isset($_GET['check'])){
    $f = $temp_dir.$_GET['check'].".done";
    echo file_exists($f)? json_encode(['status'=>'done','file'=>trim(file_get_contents($f))]) : json_encode(['status'=>'running']);
    exit;
}
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $id = uniqid();
    $bitrate = max(64, min(320, intval($_POST['bitrate'])));
    $channels = max(1, min(6, intval($_POST['channels'])));

    $samplerate_input = intval($_POST['samplerate']);
    $allowed_sr = [8000,11025,12000,16000,22050,24000,32000,44100,48000];
    $min_diff = 999; $samplerate = 44100;
    foreach($allowed_sr as $sr){ $diff = abs($sr - $samplerate_input); if($diff < $min_diff){ $min_diff = $diff; $samplerate = $sr; } }

    $format = preg_replace('/[^a-z0-9]/', '', $_POST['format']);
    $orig_name = pathinfo($_FILES['audio']['name'], PATHINFO_FILENAME);
    $in_file = $temp_dir.$id."_in";
    $out_file = $output_dir.$orig_name."_".$id.".".$format;
    $done_file = $temp_dir.$id.".done";

    if(!move_uploaded_file($_FILES['audio']['tmp_name'], $in_file)){
        echo json_encode(['status'=>'error','msg'=>'Gagal upload']); exit;
    }

    $size_mb = filesize($in_file) / 1024 / 1024;
    $estimasi = max(5, ceil($size_mb * 1.5));

    $cmd = "ffmpeg -y -i ".escapeshellarg($in_file)." -b:a {$bitrate}k -ar {$samplerate} -ac {$channels} ".escapeshellarg($out_file)." 2>&1";
    shell_exec($cmd);

    if(file_exists($out_file) && filesize($out_file) > 1000){
        file_put_contents($done_file, basename($out_file));
        unlink($in_file);
        echo json_encode(['status'=>'start','id'=>$id,'estimasi'=>$estimasi]);
    } else {
        echo json_encode(['status'=>'error','msg'=>'FFmpeg gagal']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audio Editor Pro</title>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-200 flex justify-center p-5">
<div class="bg-slate-800 p-6 rounded-2xl w-full max-w-lg shadow-2xl">
<h2 class="text-2xl font-bold text-sky-400 mb-2"><i class="fa-solid fa-music"></i> Audio Editor Pro</h2>

<form id="formAudio" enctype="multipart/form-data">
    <input type="file" name="audio" accept="audio/*" class="w-full p-2 bg-slate-700 rounded mb-3 text-sm" required>
    <div class="grid grid-cols-2 gap-3 mb-3 text-sm">
        <div><label class="block">Format</label><select name="format" class="w-full p-2 bg-slate-700 rounded"><option>mp3</option><option>wav</option><option>flac</option><option>m4a</option><option>ogg</option><option>aac</option></select></div>
        <div><label class="block">Bitrate: <span id="bval">128</span>k</label><input type="range" name="bitrate" min="64" max="320" value="128" class="w-full" oninput="bval.innerText=this.value"></div>
        <div><label class="block">Channel: <span id="cval">2</span></label><input type="range" name="channels" min="1" max="6" value="2" class="w-full" oninput="cval.innerText=this.value"></div>
        <div><label class="block">Sample: <span id="sval">44100</span>Hz</label><input type="range" name="samplerate" min="8000" max="48000" step="1000" value="44100" class="w-full" oninput="sval.innerText=this.value"></div>
    </div>
    <button id="btnSubmit" type="submit" class="w-full bg-sky-500 hover:bg-sky-600 text-black font-bold py-3 rounded"><i class="fa-solid fa-wand-magic-sparkles"></i> Convert Sekarang</button>
</form>

<!-- BOX PROSES -->
<div id="processBox" class="hidden mt-4 p-4 bg-slate-700 rounded-xl text-center">
    <div id="spinner" class="w-12 h-12 border-4 border-sky-400 border-t-transparent rounded-full animate-spin mx-auto mb-2"></div>
    <p id="statusText" class="font-bold text-sky-300">Please Wait...</p>
    <p id="subStatus" class="text-sm text-slate-400 mt-1">Decoding</p>
    <div class="w-full bg-slate-900 rounded-full h-2 mt-3"><div id="progress" class="bg-sky-500 h-2 rounded-full" style="width:0%"></div></div>
</div>

<div id="result" class="hidden mt-4"></div>
</div>

<script>
let jobId=null,timer=0,timerInterval=null,step=0;
const steps = ["Decoding","Starting Engine...","Finalizing"];

$('#formAudio').on('submit',function(e){
 e.preventDefault();
 $('#btnSubmit').prop('disabled',true).html('<i class="fa-solid fa-spinner fa-spin"></i> Uploading...');
 let fd=new FormData(this);
 let startTime = new Date().getTime();

 $.ajax({
   xhr: function(){
     let xhr = new window.XMLHttpRequest();
     xhr.upload.addEventListener("progress", function(evt){
       if (evt.lengthComputable) {
         let percent = Math.round((evt.loaded / evt.total) * 100);
         if(percent == 100) $('#btnSubmit').html('<i class="fa-solid fa-spinner fa-spin"></i> Upload Selesai');
       }
     }, false);
     return xhr;
   },
   url:'',type:'POST',data:fd,processData:false,contentType:false,dataType:'json',
   success:function(res){
     if(res.status=='start'){
       $('#btnSubmit').addClass('hidden');
       $('#processBox').removeClass('hidden');
       jobId=res.id; timer=0; step=0;
       updateStep();
       timerInterval=setInterval(updateTimer,1000);
       checkStatus();
     }
     if(res.status=='error'){alert(res.msg); $('#btnSubmit').prop('disabled',false).html('<i class="fa-solid fa-wand-magic-sparkles"></i> Convert Sekarang')}
   }
 });
});

function updateTimer(){
 timer++;
 let p = Math.min(95, Math.floor((timer/5)*100)); // estimasi 5 detik, nanti ke override
 $('#progress').css('width',p+'%');

 if(timer > 2 && step == 0){ step=1; updateStep(); }
 if(timer > 4 && step == 1){ step=2; updateStep(); }
}

function updateStep(){
 $('#subStatus').text(steps[step]);
}

function checkStatus(){
 $.get('?check='+jobId,function(res){
   res=JSON.parse(res);
   if(res.status=='done'){
     clearInterval(timerInterval);
     $('#progress').css('width','100%');
     $('#spinner').addClass('hidden');
     $('#statusText').text('Done!').removeClass('text-sky-300').addClass('text-green-400');
     $('#subStatus').text('File siap di download');
     $('#result').removeClass('hidden').html(`<a href="output/${res.file}" download class="block w-full text-center bg-green-500 hover:bg-green-600 text-black font-bold py-3 rounded"><i class="fa-solid fa-download"></i> Download ${res.file}</a>`);
   }else setTimeout(checkStatus,1500);
 });
}
</script>
</body>
</html>
