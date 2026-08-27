from pathlib import Path
p=Path('src/I18n/SiteTranslations.php')
s=p.read_text()
needle="            'A carregar…' => 'Loading…',\n"
insert=("            'Tem texto em inglês num campo em português. Quer corrigir ou anular a edição?' => "
        "'There is Portuguese text in an English field. Do you want to correct it or cancel the edit?',\n"
        "            'Anular edição' => 'Cancel edit',\n")
if needle not in s:
    raise SystemExit('catalog insertion point missing')
if 'Tem texto em inglês num campo em português.' not in s:
    s=s.replace(needle, needle+insert, 1)
p.write_text(s)
