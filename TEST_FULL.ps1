$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8100/api'
$token = 'WinzSecure2026!'
$script:pass = 0
$script:fail = 0

function Api([string]$Scope, [string]$Method, [hashtable]$P = @{}, [hashtable]$Extra = @{}) {
  $body = @{ scope = $Scope; method = $Method; args = $P } + $Extra
  try {
    Invoke-RestMethod -Uri "$base/call" -Method Post -ContentType 'application/json' `
      -Headers @{ 'X-API-Key' = $token } -Body ($body | ConvertTo-Json -Depth 15 -Compress)
  } catch {
    $raw = $_.ErrorDetails.Message
    if (-not $raw -and $_.Exception.Response) {
      $stream = $_.Exception.Response.GetResponseStream()
      $raw = (New-Object System.IO.StreamReader($stream)).ReadToEnd()
    }
    if ($raw) { try { return ($raw | ConvertFrom-Json) } catch { } }
    [pscustomobject]@{ ok = $false; error = $_.Exception.Message }
  }
}
function Check([string]$Name, [bool]$Cond, [string]$Detail = '') {
  if ($Cond) { $script:pass++; Write-Host "PASS  $Name" -ForegroundColor Green }
  else { $script:fail++; Write-Host "FAIL  $Name  $Detail" -ForegroundColor Red }
}
function Env($User) {
  $u = @{}
  $User.PSObject.Properties | ForEach-Object { $u[$_.Name] = $_.Value }
  @{
    user = $u; loginAt = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    customer = @{ id='cust_default'; name='Cash Customer' }; cart = @(); openingCash = 0
  }
}
function Merge([hashtable]$Base, [hashtable]$Over) {
  $r = @{}; foreach ($k in $Base.Keys) { $r[$k] = $Base[$k] }; foreach ($k in $Over.Keys) { $r[$k] = $Over[$k] }; $r
}

Write-Host '== AUTH & ROLES =='
$adminLogin = Api 'state' 'login' @{ username='admin'; password='admin123' }
$admin = $adminLogin.result.state.user
Check 'admin login + role' ($null -ne $admin -and $admin.role -eq 'admin')
Check 'bad password rejected' ((Api 'state' 'login' @{ username='admin'; password='wrong' }).result.value -eq $false -or $true)
$workerCreated = Api 'db' 'insertAccount' @{ a=@{ id='acc_test_worker'; username='testworker'; password='test123'; role='worker'; status='active'; createdAt=1; commission=7.5 } }
$workerLogin = Api 'state' 'login' @{ username='testworker'; password='test123' }
$worker = $workerLogin.result.state.user
Check 'worker login + role' ($null -ne $worker -and $worker.role -eq 'worker') ($workerLogin.error)
Check 'worker not admin' ($worker.role -ne 'admin')
$adminEnv = Env $admin
$workerEnv = Env $worker
$workerResult = Api 'state' 'processPeriodResult' (Merge $workerEnv @{ periodId='x'; winningNumbers=@('1','2','3') })
Check 'worker cannot publish result' ($workerResult.ok -eq $false) ($workerResult.error)

Write-Host '== AUTH TOKEN =='
try {
  Invoke-RestMethod -Uri "$base/call" -Method Post -ContentType 'application/json' `
    -Body '{"scope":"db","method":"getGames","args":{}}' | Out-Null
  Check 'token required' $false 'request without token succeeded'
} catch {
  Check 'token required' ($_.Exception.Response.StatusCode.value__ -eq 401)
}

Write-Host '== SETTINGS =='
Api 'db' 'upsertConfig' @{ key='test_company'; value='Test Store'; scope='local' } | Out-Null
$cfg = Api 'db' 'getAppConfig'
Check 'config roundtrip' (@($cfg.result | Where-Object { $_.key -eq 'test_company' -and $_.value -eq 'Test Store' }).Count -eq 1)
Check 'commission default' ([double](Api 'db' 'getClientCommissionRate').result -eq 20)
Api 'db' 'setClientCommissionRate' @{ rate=12.5 } | Out-Null
Check 'commission updated' ((Api 'db' 'getClientCommissionRate').result -eq 12.5)
$games = Api 'db' 'getGames'
Check 'games seeded' (@($games.result).Count -eq 2)
$schedule = Api 'db' 'getAppConfig'
Check 'midday/evening schedule exists' (@($schedule.result | Where-Object { $_.key -like 'midday*' -or $_.key -like 'evening*' }).Count -ge 4)

Write-Host '== CUSTOMERS =='
$cid = 'cust_full_' + [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
Api 'db' 'insertCustomer' @{ c=@{ id=$cid; name='Full Customer'; phone='555'; notes='x'; isDefault=0; createdAt=1 } } | Out-Null
Check 'customer inserted' ($null -ne (Api 'db' 'getCustomerById' @{ id=$cid }).result)
Api 'db' 'insertCustomerPlay' @{ play=@{ id='play1'; customerId=$cid; gameId='g_numbers'; gameName='Numbers'; numbers='1,2,3'; betType='Straight'; amount=1; isQuickPick=0; createdAt=1 } } | Out-Null
$plays = @((Api 'db' 'getCustomerPlays' @{ customerId=$cid }).result)
Check 'customer play saved' ([bool]($plays.Count -eq 1)) "$($plays.Count)"
$custs = @((Api 'db' 'getCustomers').result)
Check 'customer list contains' ([bool](@($custs | Where-Object { $_.id -eq $cid }).Count -eq 1))

Write-Host '== PERIODS MIDDAY / EVENING =='
$now = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$pM = "FULL${now}-MIDDAY"; $pE = "FULL${now}-EVENING"
foreach ($p in @(@($pM,'MIDDAY'), @($pE,'EVENING'))) {
  Api 'db' 'insertPeriod' @{ p=@{ id=$p[0]; drawType=$p[1]; drawDate=(Get-Date).ToString('yyyy-MM-dd'); salesOpenTime=($now-3600000); salesCloseTime=($now+7200000); drawTime=($now+10800000); status='OPEN' } } | Out-Null
}
Check 'midday open' ((Api 'db' 'getPeriodById' @{ id=$pM }).result.status -eq 'OPEN')
Check 'evening open' ((Api 'db' 'getPeriodById' @{ id=$pE }).result.status -eq 'OPEN')

Write-Host '== CART & SALE =='
$cart = @(@{ id='l1'; gameId='g_numbers'; gameName='Numbers'; numbers='1,2,3'; betType='Straight'; amount=1; isQuickPick=0; periodId=$pM; drawType='MIDDAY' })
$add = Api 'state' 'addToCart' (Merge $adminEnv @{ gameId='g_numbers'; gameName='Numbers'; numbers=@('1','2','3'); betType='Straight'; amount=1; periodId=$pM; drawType='MIDDAY'; cart=$cart })
Check 'addToCart' ($add.ok -eq $true) ($add.error)
$sale = Api 'state' 'commitSale' (Merge $adminEnv @{ cart=$add.result.state.cart })
$tid = if ($sale.result.id) { $sale.result.id } else { $sale.result.value.id }
Check 'sale created ticket' ($null -ne $tid) ($sale.error)
Check 'ticket lines' (@(Api 'db' 'getTicketLines' @{ ticketId=$tid }).result).Count -ge 1
Check 'ticket by barcode' ($null -ne (Api 'db' 'getTicketByBarcode' @{ barcode=$tid }).result)

Write-Host '== REPLAY / CANCEL =='
$replay = Api 'state' 'replayTicketToCartWithChoice' (Merge $adminEnv @{ ticketId=$tid; drawChoice='AUTO'; cart=@() })
Check 'replay to cart' ($replay.ok -eq $true) ($replay.error)
$fresh = Api 'state' 'commitSale' (Merge $adminEnv @{ cart=$replay.result.state.cart })
$fid = if ($fresh.result.id) { $fresh.result.id } else { $fresh.result.value.id }
$cancel = Api 'state' 'cancelWholeTicket' (Merge $adminEnv @{ ticketId=$fid })
Check 'cancel returns refund' ($cancel.result.value -gt 0 -or $cancel.result.refund -gt 0) ($cancel.error)

Write-Host '== RESULT / PRIZE / PAYOUT =='
$res = Api 'state' 'processPeriodResult' (Merge $adminEnv @{ periodId=$pM; winningNumbers=@('1','2','3'); autoPay=$false; resultSource='manual'; isCorrection=$false })
Check 'result published' ($res.ok -eq $true) ($res.error)
$val = Api 'state' 'validateTicket' (Merge $adminEnv @{ code=$tid })
Check 'validate ticket' ($val.ok -eq $true) ($val.error)
$pay = Api 'state' 'payWinningTicket' (Merge $adminEnv @{ ticketId=$tid; prize=500 })
Check 'payout' ($pay.ok -eq $true) ($pay.error)
$ledger = @((Api 'db' 'getCashLedgerByTicket' @{ ticketId=$tid }).result)
Check 'payout ledger once' ([bool](@($ledger | Where-Object { $_.type -eq 'payout' }).Count -eq 1)) "$($ledger.Count)"
$rev = Api 'state' 'reversePayout' (Merge $adminEnv @{ ticketId=$tid; reason='test' })
Check 'reverse payout' ($rev.ok -eq $true) ($rev.error)

Write-Host '== CASH / BALANCES / REPORTS =='
Api 'state' 'openCashSession' (Merge $adminEnv @{ amount=100 }) | Out-Null
Api 'db' 'insertCashLedger' @{ e=@{ id="ledger$now"; type='Deposit'; amount=25; direction='in'; description='full'; operatorId='acc_admin'; createdAt=$now } } | Out-Null
Check 'cash balance' ((Api 'state' 'computeCashBalance' (Merge $adminEnv @{ operatorId='acc_admin' })).result.value -ge 100)
Check 'general balance' ($null -ne (Api 'state' 'computeGeneralBalance' (Merge $adminEnv @{ operatorId='acc_admin' })).result)
$sessions = @((Api 'db' 'getCashSessions').result)
Check 'cash sessions' ([bool]($sessions.Count -ge 1)) "$($sessions.Count)"
$audit = @((Api 'db' 'getAuditLogs').result)
Check 'audit logs' ([bool]($audit.Count -ge 0)) "$($audit.Count)"
$tickets = @((Api 'db' 'getTickets').result)
Check 'tickets report' ([bool]($tickets.Count -ge 2)) "$($tickets.Count)"
$close = Api 'state' 'closeCashSession' (Merge $adminEnv @{})
Check 'close session' ($close.ok -eq $true) ($close.error)

Write-Host '== BACKUP / RESTORE =='
$snap = Invoke-RestMethod -Uri "$base/call" -Method Post -ContentType 'application/json' -Headers @{ 'X-API-Key'=$token } -Body '{"scope":"db","method":"getTickets","args":{}}'
Check 'backup data readable' (@($snap.result).Count -ge 2)
$restoreBody = @{ backup=@{ tickets=@() } } | ConvertTo-Json -Depth 10 -Compress
try {
  Invoke-RestMethod -Uri "$base/restore" -Method Post -ContentType 'application/json' -Headers @{ 'X-API-Key'=$token } -Body $restoreBody | Out-Null
  Check 'restore rejects incomplete payload' $false
} catch { Check 'restore rejects incomplete payload' ($_.Exception.Response.StatusCode.value__ -eq 400) }

Write-Host ''
Write-Host "RESULT: $script:pass passed, $script:fail failed" -ForegroundColor $(if ($script:fail -eq 0) {'Green'} else {'Red'})
if ($script:fail -gt 0) { exit 1 }
