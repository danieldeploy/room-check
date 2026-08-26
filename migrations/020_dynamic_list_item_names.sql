ALTER TABLE item_lists
    ADD COLUMN name_en VARCHAR(120) NULL AFTER name;

ALTER TABLE item_list_items
    ADD COLUMN name_en VARCHAR(80) NULL AFTER name;

-- Backfill the names that already have established English labels in the UI.
UPDATE item_lists
SET name_en = CASE name
    WHEN 'Check Geral' THEN 'General Check'
    WHEN 'Check Geral Quartos' THEN 'General Room Check'
    WHEN 'Check Casas Banho Comuns' THEN 'Shared Bathrooms Check'
    WHEN 'Check Corredores' THEN 'Corridors Check'
    WHEN 'Check Cozinhas' THEN 'Kitchens Check'
    WHEN 'Check Terraços' THEN 'Terraces Check'
    ELSE name_en
END
WHERE name_en IS NULL;

UPDATE item_list_items
SET name_en = CASE name
    WHEN 'Espelho' THEN 'Mirror'
    WHEN 'Lampadas' THEN 'Lights'
    WHEN 'Lâmpadas' THEN 'Lights'
    WHEN 'Armarios' THEN 'Wardrobes'
    WHEN 'Armários' THEN 'Wardrobes'
    WHEN 'Cabeceiras' THEN 'Headboards'
    WHEN 'Ventoinhas' THEN 'Fans'
    WHEN 'Cortinas' THEN 'Curtains'
    WHEN 'Fichas' THEN 'Power sockets'
    WHEN 'Camas' THEN 'Beds'
    WHEN 'Luzes' THEN 'Lights'
    WHEN 'Portas' THEN 'Doors'
    WHEN 'Fechaduras' THEN 'Locks'
    WHEN 'Janelas' THEN 'Windows'
    WHEN 'Chaves' THEN 'Keys'
    WHEN 'Placa de Saida' THEN 'Exit sign'
    WHEN 'Placa de Saída' THEN 'Exit sign'
    WHEN 'Caixote de Lixo' THEN 'Waste bin'
    WHEN 'Paredes' THEN 'Walls'
    WHEN 'Extintores' THEN 'Fire extinguishers'
    WHEN 'Item teste' THEN 'Test item'
    ELSE name_en
END
WHERE name_en IS NULL;
