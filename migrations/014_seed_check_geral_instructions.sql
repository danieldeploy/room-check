UPDATE item_list_items item
INNER JOIN item_lists list_row ON list_row.id = item.list_id
SET item.default_instructions = CASE item.name
    WHEN 'Espelho' THEN 'Verificar se está limpo e sem danos.'
    WHEN 'Lampadas' THEN 'Confirmar que todas as lâmpadas acendem.'
    WHEN 'Armarios' THEN 'Verificar a limpeza e o funcionamento das portas.'
    WHEN 'Cabeceiras' THEN 'Confirmar que estão limpas e bem fixas.'
    WHEN 'Ventoinhas' THEN 'Testar o funcionamento e verificar a limpeza.'
    WHEN 'Cortinas' THEN 'Verificar a limpeza e o movimento das cortinas.'
    WHEN 'Fichas' THEN 'Confirmar que estão fixas e sem danos visíveis.'
    WHEN 'Camas' THEN 'Verificar a estabilidade e o estado das camas.'
    WHEN 'Luzes' THEN 'Testar todas as luzes do quarto.'
    WHEN 'Portas' THEN 'Confirmar que abrem e fecham corretamente.'
    WHEN 'Fechaduras' THEN 'Testar a fechadura e o trinco da porta.'
    WHEN 'Janelas' THEN 'Verificar abertura, fecho e estado dos vidros.'
    WHEN 'Chaves' THEN 'Confirmar que as chaves estão disponíveis e funcionam.'
    WHEN 'Placa de Saida' THEN 'Verificar se está visível e bem fixada.'
    WHEN 'Caixote de Lixo' THEN 'Confirmar que está limpo e em bom estado.'
    WHEN 'Paredes' THEN 'Verificar manchas, fissuras ou danos.'
    WHEN 'Hangers' THEN 'Confirmar a quantidade e o estado dos cabides.'
    ELSE item.default_instructions
END
WHERE list_row.is_system = 1
  AND item.default_instructions = ''
  AND item.name IN (
      'Espelho', 'Lampadas', 'Armarios', 'Cabeceiras', 'Ventoinhas',
      'Cortinas', 'Fichas', 'Camas', 'Luzes', 'Portas', 'Fechaduras',
      'Janelas', 'Chaves', 'Placa de Saida', 'Caixote de Lixo',
      'Paredes', 'Hangers'
  );
