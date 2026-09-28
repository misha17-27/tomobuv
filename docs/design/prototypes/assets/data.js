/* Реальные данные с tomobuv.com.ua (собраны 27.09.2026). Картинки грузятся с оригинального сайта. */
window.TOM = (function () {
  const BASE = 'https://tomobuv.com.ua';
  const img = p => BASE + p;

  const sizes = (list) => list.map(([t, h]) => ({ t, h: BASE + h }));

  const catalog = [
    { t: 'Акция', h: BASE + '/category/aktsiya/', hot: true },
    { t: 'Украинская обувь', h: BASE + '/category/ukrainskaya-obuv/' },
    {
      t: 'Детская обувь', h: BASE + '/category/dyetskaya-obuv/', img: img('/wa-data/public/shop/wmimageincatPlugin/categories/128/image_29.jpg'),
      children: [
        { t: 'Чехлы-бахилы', h: BASE + '/category/chehly-bahily-290/' },
        { t: 'Угги', h: BASE + '/category/uggi-278/', sizes: sizes([['12-26', '/category/12-26-279/'], ['26-32', '/category/26-32-280/'], ['32-38', '/category/32-38-281/'], ['36-41', '/category/36-41-282/']]) },
        { t: 'Кроссовки', h: BASE + '/category/optom-krossovki-detyam/', sizes: sizes([['12-26', '/category/razmernyj-ryad-ot-12-26-ot-proizvoditelya/'], ['26-32', '/category/razmernyj-ryad-ot-26-32-ot-proizvoditelya/'], ['32-38', '/category/razmernyj-ryad-ot-32-38-ot-proizvoditelya/'], ['36-41', '/category/razmernyj-ryad-ot-36-41-ot-proizvoditelya/']]) },
        { t: 'Туфли', h: BASE + '/category/tufli-na-malchika-i-devochku-optom/', sizes: sizes([['12-26', '/category/setka-razmerov-12-26-obuv-opt/'], ['26-32', '/category/setka-razmerov-26-32-obuv-opt/'], ['32-38', '/category/setka-razmerov-32-38-obuv-opt/'], ['36-41', '/category/setka-razmerov-36-41-obuv-opt/']]) },
        { t: 'Зимняя обувь', h: BASE + '/category/zimnyaya-obuv-malchikam-i-devochkam/', sizes: sizes([['12-26', '/category/kachestvennaya-zimnyaya-obuv-12-26-optom/'], ['26-32', '/category/kachestvennaya-zimnyaya-obuv-26-32-optom/'], ['32-38', '/category/kachestvennaya-zimnyaya-obuv-32-38-optom/'], ['36-41', '/category/kachestvennaya-zimnyaya-obuv-36-41-optom/']]) },
        { t: 'Весна-Осень', h: BASE + '/category/obuv-detskaya-vesna-osen-optom/', sizes: sizes([['12-26', '/category/razmernyj-ryad-ot-12-26-obuv/'], ['26-32', '/category/razmernyj-ryad-ot-26-32-obuv/'], ['32-38', '/category/razmernyj-ryad-ot-32-38-obuv/'], ['36-41', '/category/razmernyj-ryad-ot-36-41-obuv/']]) },
        { t: 'Летняя обувь', h: BASE + '/category/detskaya-letnyaya-obuv-opt/', sizes: sizes([['12-26', '/category/razmer-12-26-optom-ot-proizvoditelya/'], ['26-32', '/category/razmer-26-32-optom-ot-proizvoditelya/'], ['32-38', '/category/razmer-32-38-optom-ot-proizvoditelya/'], ['36-41', '/category/razmer-36-41-optom-ot-proizvoditelya/']]) },
        { t: 'Мокасины', h: BASE + '/category/detskiye-mokasiny/', sizes: sizes([['12-26', '/category/12-26-221/'], ['26-32', '/category/26-32-222/'], ['32-38', '/category/32-38-223/']]) },
        { t: 'Кеды', h: BASE + '/category/sportivnie-kedi-detyam-opt/', sizes: sizes([['12-26', '/category/kedy-ryad-ot-12-26-ukraina/'], ['26-32', '/category/kedy-ryad-ot-26-32-ukraina/'], ['32-38', '/category/kedy-ryad-ot-32-38-ukraina/'], ['36-41', '/category/kedy-ryad-ot-36-41-ukraina/']]) },
        { t: 'Шлепанцы', h: BASE + '/category/shlepancy-detskie-optom/', sizes: sizes([['12-26', '/category/razmer-12-26-optom-obuv-dlya-detej/'], ['26-32', '/category/razmer-26-32-optom-obuv-dlya-detej/'], ['32-38', '/category/32-38/'], ['36-41', '/category/razmer-36-41-optom-obuv-dlya-detej/']]) },
        { t: 'Тапочки', h: BASE + '/category/detskie-tapochki-optom/', sizes: sizes([['11-25', '/category/tapochki-detskoe-kupit-ryad-11-25-optom/'], ['25-30', '/category/tapochki-detskoe-kupit-ryad-25-30-optom/'], ['30-35', '/category/tapochki-detskoe-kupit-ryad-30-35-optom/'], ['36-41', '/category/tapochki-detskoe-kupit-ryad-36-41-optom/']]) },
        { t: 'Сапоги резиновые', h: BASE + '/category/sapogi-detskie-rezinovye-opt/' },
        { t: 'Валенки', h: BASE + '/category/valenki-265/' },
        { t: 'Сникерсы', h: BASE + '/category/detskiye-snikersy/' },
        { t: 'Чешки', h: BASE + '/category/tancevalnye-cheshki-dlya-detej/' },
        { t: 'Бутсы', h: BASE + '/category/futbolnye-butsy-dlya-detej/' }
      ]
    },
    {
      t: 'Мужская обувь', h: BASE + '/category/muzhskaya-obuv/', img: img('/wa-data/public/shop/wmimageincatPlugin/categories/127/image_27.jpg'),
      children: [['Сабо', '/category/sabo-292/'], ['Берцы', '/category/bertsy-291/'], ['Резиновая обувь', '/category/rezinovaya-obuv-242/'], ['Кроксы', '/category/mujskiye-kroksy/'], ['Мокасины', '/category/mujskiye-mokasiny/'], ['Угги', '/category/mujskiye-uggi/'], ['Галоши', '/category/mujskiye-galoshi/'], ['Летняя обувь', '/category/mujskaya-letnyaya-obuv/'], ['Шлепанцы', '/category/mujskiye-shlepantsy/'], ['Кроссовки', '/category/mujskiye-krossovki/'], ['Туфли', '/category/mujskiye-tufli/'], ['Кеды', '/category/mujskiye-kedy/'], ['Комнатные тапочки', '/category/mujskiye-komnatnye-tapoochki/'], ['Бутсы', '/category/mujskiye-butsy/'], ['Весна-Осень', '/category/mujskiye-vesno-osen/'], ['Зимняя обувь', '/category/mujskaya-zimnaya-obuv/']].map(([t, h]) => ({ t, h: BASE + h }))
    },
    {
      t: 'Женская обувь', h: BASE + '/category/zhyenskaya-obuv/', img: img('/wa-data/public/shop/wmimageincatPlugin/categories/129/image_28.jpg'),
      children: [['Чешки', '/category/cheshki-262/'], ['Ботфорты', '/category/botforty-236/'], ['Ботильоны', '/category/botilony-234/'], ['Галоши', '/category/galoshi-227/'], ['Берцы', '/category/bertsy-228/'], ['Сникерсы', '/category/jenskiye-snikersy/'], ['Кроксы', '/category/jenskiye-kroksy/'], ['Эспадрильи', '/category/jenskiye-espadrili/'], ['Сабо', '/category/jenskiye-sabo/'], ['Балетки', '/category/jenskiye-baletki/'], ['Летняя обувь', '/category/jenskaya-letnaya-obuv/'], ['Шлепанцы', '/category/jenskiye-shlepantsy/'], ['Зимняя обувь', '/category/jenskaya-zimnaya-obuv/'], ['Кеды', '/category/jenskiye-kedy/'], ['Туфли', '/category/jenskiye-tufli/'], ['Кроссовки', '/category/jenskiye-krossovki/'], ['Весна-Осень', '/category/jenskiye-demisezonnaya-obuv/'], ['Комнатные тапочки', '/category/jenskiye-komnatnyye-tapochki/'], ['Угги', '/category/jenskiye-uggi/'], ['Сапоги резиновые', '/category/jenskiye-sapogi-rezinovye/']].map(([t, h]) => ({ t, h: BASE + h }))
    },
    {
      t: 'Подростковая обувь', h: BASE + '/category/podrostkovaya-obuv-208/', img: img('/wa-data/public/shop/wmimageincatPlugin/categories/208/image_11.jpg'),
      children: [['Берцы', '/category/bertsy-287/'], ['Сникерсы', '/category/snikersy-288/'], ['Мокасины', '/category/podrostok-mokasiny/'], ['Кроссовки', '/category/podrostok-krossovki/'], ['Кеды', '/category/podrostok-kedy/'], ['Зимняя обувь', '/category/podrostok-zimnyaya-obuv/'], ['Тапочки', '/category/podrostok-tapochki/'], ['Туфли', '/category/podrostok-tufli/'], ['Весна-Осень', '/category/podrostok-vesna-osen/'], ['Угги', '/category/podrostok-uggi/'], ['Сапоги резиновые', '/category/podrostok-sapogi-rezinovye/'], ['Летняя обувь', '/category/podrostok-letnyaya-obuv/']].map(([t, h]) => ({ t, h: BASE + h }))
    }
  ];

  // [артикул, url, путь картинки, размер, брэнд, пар в ящике, цена за пару, скидка %, группа]
  const raw = [
    ['60189A', '/product/60189a/', '21/19/1451921/images/1611694/1611694', '32-37', 'Tom.m', 8, 1020, 0, 'new'],
    ['60189B', '/product/60189b/', '20/19/1451920/images/1611693/1611693', '32-37', 'Tom.m', 8, 1020, 0, 'new'],
    ['60190B', '/product/novyj-tovar30020004862754916/', '19/19/1451919/images/1611692/1611692', '32-37', 'Tom.m', 8, 920, 0, 'new'],
    ['60190F', '/product/60190f/', '18/19/1451918/images/1611691/1611691', '32-37', 'Tom.m', 8, 920, 0, 'new'],
    ['60190E', '/product/60190e/', '17/19/1451917/images/1611690/1611690', '32-37', 'Tom.m', 8, 920, 0, 'new'],
    ['60190A', '/product/60190a/', '16/19/1451916/images/1611689/1611689', '32-37', 'Tom.m', 8, 920, 0, 'new'],
    ['60192A', '/product/60192a/', '15/19/1451915/images/1611688/1611688', '32-37', 'Tom.m', 8, 1060, 0, 'new'],
    ['60192B', '/product/novyj-tovar30019937032470628/', '14/19/1451914/images/1611687/1611687', '32-37', 'Tom.m', 8, 1060, 0, 'new'],
    ['60192C', '/product/60192c/', '13/19/1451913/images/1611686/1611686', '32-37', 'Tom.m', 8, 1060, 0, 'new'],
    ['65193A', '/product/novyj-tovar30017757068132452/', '11/19/1451911/images/1611685/1611685', '26-33', 'Tom.m', 8, 840, 0, 'new'],
    ['65193H', '/product/novyj-tovar30017752722833508/', '10/19/1451910/images/1611684/1611684', '26-33', 'Tom.m', 8, 840, 0, 'new'],
    ['65193F', '/product/65193f/', '09/19/1451909/images/1611683/1611683', '28-33', 'Tom.m', 8, 840, 0, 'new'],
    ['65210A', '/product/65210a/', '08/19/1451908/images/1611682/1611682', '33-38', 'Tom.m', 8, 930, 0, 'new'],
    ['65210E', '/product/novyj-tovar30017718514090084/', '07/19/1451907/images/1611680/1611680', '33-36', 'Tom.m', 8, 930, 0, 'new'],
    ['65210D', '/product/novyj-tovar30017703028719716/', '06/19/1451906/images/1611679/1611679', '33-38', 'Tom.m', 8, 930, 0, 'new'],
    ['60179A', '/product/novyj-tovar30017698918301796/', '05/19/1451905/images/1611678/1611678', '32-37', 'Tom.m', 8, 980, 0, 'new'],
    ['60179B', '/product/60179b/', '04/19/1451904/images/1611677/1611677', '32-37', 'Tom.m', 8, 980, 0, 'new'],
    ['65210B', '/product/novyj-tovar30017674826219620/', '03/19/1451903/images/1611681/1611681', '33-38', 'Tom.m', 8, 930, 0, 'new'],
    ['60179D', '/product/novyj-tovar30017669105188964/', '02/19/1451902/images/1611676/1611676', '32-37', 'Tom.m', 8, 980, 0, 'new'],
    ['60180A', '/product/novyj-tovar30017664374014052/', '01/19/1451901/images/1611675/1611675', '32-37', 'Tom.m', 8, 1060, 0, 'new'],
    ['60180B', '/product/novyj-tovar30017660615917668/', '00/19/1451900/images/1611674/1611674', '32-37', 'Tom.m', 8, 1060, 0, 'new'],
    ['60180D', '/product/novyj-tovar30017654441902180/', '99/18/1451899/images/1611673/1611673', '32-37', 'Tom.m', 8, 1060, 0, 'new'],
    ['60180F', '/product/60180f/', '98/18/1451898/images/1611672/1611672', '32-37', 'Tom.m', 8, 1060, 0, 'new'],
    ['60114B', '/product/novyj-tovar30012725849489508/', '96/18/1451896/images/1611671/1611671', '32-37', 'Tom.m', 8, 980, 0, 'new'],
    ['Кроссовки CR A705-5', '/product/krossovki-cr-a705-5/', '27/66/1266627/images/1408246/1408246', '41-45', 'CR', 8, 630, 93, 'promo'],
    ['Кросівки Jong•Golf B11661-0', '/product/krosivki-jonggolf-b11661-0/', '99/14/1451499/images/1611291/1611291', '26-31', 'Jong•Golf', 8, 400, 16, 'promo'],
    ['Кросівки Jong•Golf B11661-5', '/product/krosivki-jonggolf-b11661-5/', '00/15/1451500/images/1611292/1611292', '26-31', 'Jong•Golf', 8, 400, 16, 'promo'],
    ['Кросівки Jong•Golf B11661-6', '/product/krosivki-jonggolf-b11661-6/', '01/15/1451501/images/1611293/1611293', '26-31', 'Jong•Golf', 8, 400, 16, 'promo'],
    ['Босоніжки Jong•Golf C20587-0', '/product/bosonizhki-jonggolf-c20587-0/', '59/06/1450659/images/1610453/1610453', '30-35', 'Jong•Golf', 8, 330, 23, 'promo'],
    ['Кросівки Jong•Golf B11593-2', '/product/krosivki-jonggolf-b11593-2/', '89/14/1451489/images/1611281/1611281', '26-31', 'Jong•Golf', 8, 350, 18, 'promo'],
    ['Кросівки Jong•Golf B11593-6', '/product/krosivki-jonggolf-b11593-6/', '90/14/1451490/images/1611282/1611282', '26-31', 'Jong•Golf', 8, 350, 18, 'promo'],
    ['Босоніжки Jong•Golf C20614-0', '/product/bosonizhki-jonggolf-c20614-0/', '62/06/1450662/images/1610456/1610456', '32-37', 'Jong•Golf', 8, 280, 15, 'promo'],
    ['68132E', '/product/novyj-tovar30012673202585700/', '91/18/1451891/images/1611666/1611666', '32-37', 'Tom.m', 8, 1020, 0, 'women'],
    ['60149B', '/product/novyj-tovar30010055503904868/', '82/18/1451882/images/1611658/1611658', '36-41', 'Tom.m', 8, 1060, 0, 'women'],
    ['68127A', '/product/novyj-tovar30010004517945444/', '79/18/1451879/images/1611656/1611656', '28-35', 'Tom.m', 8, 930, 0, 'women'],
    ['68127F', '/product/novyj-tovar30009982187470948/', '77/18/1451877/images/1611654/1611654', '28-35', 'Tom.m', 8, 930, 0, 'women'],
    ['65189D', '/product/novyj-tovar30009967155085412/', '75/18/1451875/images/1611652/1611652', '28-33', 'Tom.m', 8, 840, 0, 'women'],
    ['65189E', '/product/novyj-tovar30009958883917924/', '74/18/1451874/images/1611651/1611651', '28-33', 'Tom.m', 8, 840, 0, 'women']
  ];

  const products = raw.map(([name, h, p, size, brand, box, price, off, group], i) => ({
    id: 'p' + i, name, url: BASE + h,
    img: `${BASE}/wa-data/public/shop/products/${p}.400.jpg`,
    size, brand, box, price, boxPrice: price * box,
    oldPrice: off ? Math.round(price / (1 - off / 100) / 10) * 10 : 0,
    off, group, inStock: true
  }));

  const brands = ['A.Dama', 'A.L.S.K.', 'A.N.I.One', 'AAPR-Kaker', 'ABM', 'AD', 'AOKA', 'ARTO', 'ARZO', 'ASHIGULI', 'AVM', 'Aba', 'Tom.m', 'Jong•Golf', 'CR', 'Alaska']
    .map(t => ({ t, h: `${BASE}/brand/${encodeURIComponent(t)}/` }));

  const letters = 'A B C D E F G H I J K L M N O P Q R S T U V W X Y Z Б В З К Л М Н С Ц Ш Э'.split(' ');

  const pages = [
    { t: 'О компании', h: BASE + '/o-kompanii/' },
    { t: 'Доставка и оплата', h: BASE + '/dostavka-i-oplata/' },
    { t: 'Условия сотрудничества', h: BASE + '/usloviya-sotrudnichestva/' },
    { t: 'Контакты', h: BASE + '/kontakty/' },
    { t: 'Статьи', h: BASE + '/stati/' }
  ];

  const articles = [
    { t: 'Обзорная статья бренда детской обуви Солнце', d: 'Покупка детской обуви всегда достаточно сложная задача для родителей. На современном рынке представлен огромный ассортимент как моделей, так и торговых марок…', h: BASE + '/stati/' },
    { t: 'Обзорная статья бренда детской обуви Ytop', d: 'С наступлением нового сезона родители задумываются о покупке обуви для своих деток: модели должны быть не только красивыми и модными, но и удобными…', h: BASE + '/stati/' }
  ];

  const features = [
    { t: 'Способы оплаты', d: 'Покупателям доступны различные способы оплаты', h: BASE + '/dostavka-i-oplata/' },
    { t: 'Доставка по всей Украине', d: 'Отправляем всеми почтами. От 20 ящиков — бесплатно!', h: BASE + '/dostavka-i-oplata/' },
    { t: 'Отзывы', d: 'Посмотреть отзывы наших заказчиков о работе магазина', h: BASE + '/o-kompanii/' }
  ];

  const contacts = {
    phones: ['+38 (093) 275-3070', '+38 (050) 761-6901'],
    tel: ['+380932753070', '+380507616901'],
    hours: 'Пн, Вт, Ср, Чт, Сб, Вс · 06:00—18:00',
    email: 'tomobuv@gmail.com',
    address: 'Украина, Одесса, Промрынок 7 км',
    since: 2011
  };

  const links = {
    login: BASE + '/login/', signup: BASE + '/signup/', cart: BASE + '/cart/', checkout: BASE + '/order/',
    favorites: BASE + '/search/?_balance_type=favorites', viewed: BASE + '/search/?_balance_type=viewed',
    brands: BASE + '/brand/', search: BASE + '/search/?query=', articles: BASE + '/stati/'
  };

  const images = {
    logo: BASE + '/wa-data/public/site/themes/balance/img/logo.png',
    slides: [1, 2, 3].map(n => `${BASE}/wa-data/public/shop/themes/balance/img/slider/slide_${n}.jpg`),
    banners: [BASE + '/wa-data/public/shop/themes/balance/img/banners/banner-1_1.jpg', BASE + '/wa-data/public/shop/themes/balance/img/banners/banner-2_1.jpg', BASE + '/wa-data/public/shop/themes/balance/img/banners/banner-2_2.jpg']
  };

  return { BASE, catalog, products, brands, letters, pages, articles, features, contacts, links, images };
})();
