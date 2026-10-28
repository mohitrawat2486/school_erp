<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><style>
@page{size:A4 portrait;margin:28pt}body{font-family:'DejaVu Sans',sans-serif;font-size:11px;color:#172b38;margin:0}.card{page-break-before:always}.card.first{page-break-before:auto}.brand{width:100%;border-bottom:1px solid #cbd5e1;padding-bottom:12px}.brand td{border:0}.brand h1{font-size:24px;margin:0 0 5px}.address{font-size:9px;color:#526574}.logo{width:62px;height:62px}.title{text-align:center;margin:15px 0}.title h2{font-size:18px;letter-spacing:2px;color:#8b2f24;margin:0 0 4px}.info,.totals{width:100%;border-collapse:collapse;margin:14px 0}.info td{width:50%;border:1px solid #dbe3e9;background:#f6f8fa;padding:8px}.label{font-size:9px;color:#526574;text-transform:uppercase}.value{font-size:12px;font-weight:bold}.marks{width:100%;border-collapse:collapse;margin:14px 0}.marks th,.marks td{border:1px solid #cbd5e1;padding:7px 4px;text-align:center}.marks th{background:#f1f5f9;font-size:10px}.marks th:first-child,.marks td:first-child{text-align:left;width:29%}thead{display:table-header-group}tr{page-break-inside:avoid}.totals td{width:33%;border:1px solid #dbe3e9;padding:9px}.totals strong{font-size:14px}.remarks{margin:15px 0}.remarks p{margin:4px 0}.signatures{width:100%;margin-top:32px;page-break-inside:avoid}.signatures td{width:50%;text-align:center;padding:0 16px}.signatures div{border-top:1px solid #64748b;padding-top:7px}.footer{border-top:1px solid #dbe3e9;margin-top:20px;padding-top:8px;text-align:center;font-size:8px;color:#64748b}.keep{page-break-inside:avoid}
</style></head><body>
@foreach($cards as $card)
<div class="card {{ $loop->first?'first':'' }}">
@include('report-cards.pdf-card',$card)
</div>
@endforeach
</body></html>
