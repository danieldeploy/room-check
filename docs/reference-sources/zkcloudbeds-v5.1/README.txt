ZKCloudbedsAuto V5.0 Enterprise

Fluxo de produção:
1) Cloudbeds: abre dashboard [PROPERTY_ID], lê apenas #tab_arrivals-today.
2) Cloudbeds: recolhe data-res-id, Guest, Room e chama/captura API interna get_reservation para ler notes[].notes.
3) Cloudbeds: extrai PIN apenas depois da palavra "pin" e grava CSV.
4) ZKAccess: login robusto, pesquisa em lote por Room, cria mapa First Name -> EmployeeID.
5) ZKAccess: abre EmployeeID direto, lê #id_Password, descodifica, compara e atualiza só se diferente.
6) Relatórios CSV e HTML em reports/.

Comandos:
- install.bat                  Instala dependências e Chromium.
- run_login_setup.bat          Abre sessões; completar Cloudbeds MFA se necessário.
- run_cloudbeds.bat            Testa apenas Cloudbeds.
- run_zkaccess_test.bat        Testa apenas ZKAccess.
- run_auto_test.bat            Fluxo completo em dry_run.
- install_daily_1255_task.bat  Agenda execução diária às 12:55 no Windows.

Segurança:
- config.example.json vem com "dry_run": true. Ver MIGRATION.md antes de qualquer execução.
- Para ativar alterações reais, alterar para "dry_run": false após validar logs.

Notas:
- ZKAccess codifica passwords com pares AF/BF/CF...; a aplicação descodifica antes de comparar.
- Se existirem funcionários duplicados com o mesmo First Name, a aplicação pára em segurança.
