<?php

namespace Modules\Orders2\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

use Modules\Orders2\Entities\PlanningSection2;
use Modules\Orders2\Entities\PlanningOrders2;

use Modules\Assets\Entities\AssetsAsset;
use Illuminate\Support\Facades\DB;

use Modules\Orders2\Entities\Order;
use Modules\Orders2\Entities\Phase;
use Modules\Orders2\Entities\Operation;
use Modules\Orders2\Entities\Event;
use Modules\Orders2\Entities\Status;
use Modules\Orders2\Entities\SumUnit;
use Modules\Orders2\Entities\Material;

use Modules\Orders2\Entities\WorkloadSections;

use Modules\Orders2\Jobs\ReplanningProduction;



use Carbon\Carbon;
use DateTime;

class PlanningController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('orders2::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('orders2::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('orders2::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('orders2::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }

        public function orderPlanning(Request $request)
        {
        // dd("planificacion preprod con nuevas tablas ");

            // use Modules\Orders2\Entities\Order;
            // use Modules\Orders2\Entities\Phase;
            // use Modules\Orders2\Entities\Operation;
            // use Modules\Orders2\Entities\Event;
            // use Modules\Orders2\Entities\Status;
            // use Modules\Orders2\Entities\SumUnit;
            // use Modules\Orders2\Entities\Material;


            $order = Order::where('id', $request->id)
                ->first();

            // dd($order);

            $orderId = $order->id; // es el id de MES
            $orderofOrderId = $order->ofOrderId;
            $orderCode = $order->code;


            //dd($orderId, $orderofOrderId, $orderCode);

            $orderData = json_decode($order->dataJson, TRUE)['phases'];

            // dd($orderData);

            $materials = json_decode($order->dataJson, TRUE)['materials'];

            $plannedQuantityMaterials = [];

            foreach ($orderData as $elemento) {

                // Verificar si existe la clave 'materials' y si es un array
                if (isset($elemento['materials']) && is_array($elemento['materials'])) {


                    foreach ($elemento['materials'] as $material) {

                        $id = $material['id'];
                        $quantityPlanned = $material['quantityPlanned'];

                        $plannedQuantityMaterials[] = [
                            'id' => $id,
                            'quantityPlanned' => $quantityPlanned
                        ];
                        
                    
                    }
                }
            }

            

            foreach ($materials as &$material) {
                foreach ($plannedQuantityMaterials as $plannedMaterial) {
                    if ($material['id'] === $plannedMaterial['id']) {
                        $material['quantityPlanned'] = $plannedMaterial['quantityPlanned'];
                    }
                }
            }

        // dd($materials, $plannedQuantityMaterials);

        // agregar estos 2 casos a los errores de materiales antes de la planificacion
        // puede darse el caso tambien que el material no tenga stock y que la cantidad a recibir sea menor al stock faltante ejemplo (24/73927)
        // que el material tenga stock negativo tenga fecha de recepcion pero la cantidad a recibir sea 0

            $materialsFilter = [];

            // aca deberia buscar los materiales ignorados para no tenerlo encuenta en la planificacion

            // $materialsIgnore = $request->materielasIgnore;
            // $materialsIgnore = json_decode($materialsIgnore, true);

            //dd($request->restrictionMaterial);

            $materialsIgnore = $request->restrictionMaterial;


            foreach ($materials as $item) {

            // dd($item);

                if (in_array($item['id'], $materialsIgnore)) {

                    continue; // Continuar con la siguiente iteración del bucle
                }

                

                if ($item["quantityAvailable"]< 0 && $item['receptionDate'] == null) {

                

                    //En este caso el material no tiene un stock y no tiene una fecha estimada de cuando llegara el material por lo cual no puedo planificar
                    
                    return response()->json(['success' => false, 'errors' => [
                        'title' => 'Planificacion',
                        'description' => "Esta OF no puede ser planificacda, existe un problema con uno de sus materiales,
                        el .$item[description] no posee stock disponible y no tiene una fecha estimada de llegada, lo cual hace que no
                        se pueda realizar la planificacion de manera correcta"
                        ]], 499);    

        

                }else if($item["quantityAvailable"]< 0 && $item['quantityReception'] < $item["quantityAvailable"]){

                    //En este caso el material no tiene un stock y y tiene una cantidad a recibir con fecha pero la cantidad a recibir es menor a la cantidad negativa
                    // de falta de stock esto incluye el caso de cuando la cantidad a recibir sea 0 

                    return response()->json(['success' => false, 'errors' => [
                        'title' => 'Planificacion',
                        'description' => "Esta OF no puede ser planificacda, existe un problema con uno de sus materiales,
                        el .$item[description] no posee stock disponible  y la cantidad a recibir en el proximo pedido
                        no alcanza a cubrir el stock negativo"
                        ]], 499); 
                    

                }else if($item["quantityAvailable"] < $item["quantityPlanned"] && $item['receptionDate'] == null){

                //dd($item); 

                    return response()->json(['success' => false, 'errors' => [
                        'title' => 'Planificacion',
                        'description' => "Esta OF no puede ser planificacda, existe un problema con uno de sus materiales,
                        el .$item[description] posee un stock menor al necesario y no posee una fecha de recepcion"
                        ]], 499); 
                    

                }else if( ($item["quantityAvailable"] + $item['quantityReception']) < $item["quantityPlanned"]){

                    //dd($item); 
    
                    return response()->json(['success' => false, 'errors' => [
                        'title' => 'Planificacion',
                        'description' => "Esta OF no puede ser planificacda, existe un problema con uno de sus materiales,
                        el .$item[description] posee un stock menor al necesario y el pedido no alcanza para cubrir la cantidad necesaria
                        del material para esta OF "
                        ]], 499); 
                    
    
                }else if($item['receptionDate'] !== null) {

                    //dd($item);

                    $materialsFilter[] = [

                        'id' => '- Material Electricfor -' . $item["id"],
                        'receptionDate' => $item['receptionDate'],
                    ];
                }    
                        
            }

            //dd("176");


            $phaseData = array_map(function ($item) {
                return [
                    'phaseIdAlfa' => $item['id'],
                    'phaseDescription' => $item['description'],
                    'operationsIdAlfa' => $item['operations'],
                    'materialsIdAlfa' => $item['materials'],
                ];
            }, $orderData);

            //dd($phaseData);


            $filteredData = array_filter($phaseData, function ($item) {
                return isset($item['operationsIdAlfa']);
            });

            //dd($filteredData);

            $phases = array_map(function ($item) use ($materialsFilter) {
                // Obtener el campo "operationsIdAlfa" que contiene las operaciones

                //dd($item);

                $operations = $item['operationsIdAlfa'];

                // Obtener los "id" de las operaciones y sumar los valores de los tiempos teóricos
                $operationIds = [];
                $totalPreparationTime = 0;
                $totalManualTime = 0;
                $totalMachineTime = 0;

                $sectionCodeAlfa = [];

                foreach ($operations as $operation) {

                    //dd($operation);

                    $operationIds[] = $operation['id'];

                    //dd($operation);


                    $sectionCode = $operation["resources"][0]['sectionCode'] . '-' . $operation["resources"][0]['sectionDescription'];

                    //dd($sectionCode);

                    if (isset($sectionCodeAlfa[$sectionCode])) {
                        $sectionCodeAlfa[$sectionCode] += $operation['theoreticalPreparationTime'] + $operation['theoreticalManualTime'] + $operation['theoreticalMachineTime'];
                    } else {
                        // Si el código de sección no existe en $sectionCodeAlfa, crear una nueva entrada
                        $sectionCodeAlfa[$sectionCode] = $operation['theoreticalPreparationTime'] + $operation['theoreticalManualTime'] + $operation['theoreticalMachineTime'];
                    }
                }

                $materials = $item['materialsIdAlfa'];

                $materialsAlfa = []; // Un nuevo array para almacenar los valores de "id"}

                //dd($materials );

                foreach ($materials as $material) {

                    $materialPhaseName = $material['code'] . '- Material Electricfor -' . $material["id"];

                    $materialPhaseNameNew = '- Material Electricfor -' . $material["id"];


                    $materialsAlfa[] = [
                        'materialName' => $materialPhaseName,
                        'materialIdAlfa' => $material['id'],
                        'receptionDate' => null,
                        'materialNameFilter' => $materialPhaseNameNew,
                    ];
                }

                foreach ($materialsAlfa as &$materialAlfa) {
                    //dd($materialAlfa);
                    foreach ($materialsFilter as $materialFilter) {
                        //dd($materialFilter);
                        if (trim($materialAlfa['materialNameFilter']) === trim($materialFilter['id'])) {
                            //dd("encintro conincidencia", $materialFilter );

                            $materialAlfa['receptionDate'] = $materialFilter['receptionDate'];
                            //dd($materialAlfa);
                            break; // Termina la búsqueda si se encontró una coincidencia
                        }
                    }
                }

                // Devolver el nuevo array con los datos requeridos
                return [
                    'phaseAlfa' => $item['phaseIdAlfa'],
                    'phaseDescription' => $item['phaseDescription'],
                    'operationIds' => $operationIds,
                    'sectionAlfaTime' => $sectionCodeAlfa,
                    'materialsAlfa' => $materialsAlfa,

                ];

                //dd("linea 359");

            }, $filteredData);

            //dd($phases);

            //esto podria eliminarlo 

            // $length = count($phases);

            // for ($i = 0; $i < $length; $i++) {
            //     $phases[$i]['phaseAlfa'] = 'Fase_O ' . $phases[$i]['phaseAlfa'];

            //     $operationCount = count($phases[$i]['operationIds']);

            //     for ($j = 0; $j < $operationCount; $j++) {
            //         $phases[$i]['operationIds'][$j] = 'Operación_O ' . $phases[$i]['operationIds'][$j];
            //     }
            // }

            //dd($phases);

            $data = [];


            foreach ($phases as $item) {

                //dd("fase", $item);

                $faseSectionCount = count($item['sectionAlfaTime']);

                $differentSections = false;

                

                //dd($faseSectionCount);

                if ($faseSectionCount > 1) {

                    //como se que tengo mas de una operacion quiero verificar que no sean de distintas secciones

                    //creo una variable para hacer comprobaciones

                    $faseSection = $item['sectionAlfaTime'];    

                    foreach ($faseSection as $key => $value) {

                        //itero por el array de secciones y elimino aquellas seciones tiempo maquina 
                    
                        if(preg_match('/TM/', $key) ) {

                            unset($faseSection[$key]);

                        } 
                    }

                    // valido si aun hay mas de 1 operacion para compararlas 

                    if( count($faseSection) > 1){

                        $sections  = array_keys($faseSection);

                        // cuento cuantas veces aparece cada seccion

                        $sectionRepetitions = array_count_values($sections);

                        $repeats = count($sectionRepetitions) !== count($sections);

                        if($repeats !== true){

                            $differentSections = true;

                        }
                        
                    

                    }

                    
                }

                // ahora tengo una variable que me indica que dentro de una fase hay secciones distintas debo
                //ver como separarlas por los tiempos 
                
                // primero voy a hacer la logica de si no existen secciones diferentes dentro de una fase, hago lo que estaba haciendo hasta ahora

                if($differentSections == false ){



                    $maxReceptionDate = null;
                    $restrictionMaterial = null;
        
                    $phaseId = null;
        
                    $phaseId = Phase::where('ofFaseId', $item["phaseAlfa"])->value('id');
        
                    //dd($item['operationIds']);
        
                    // Guardar datos de operationIds en operationsName
                    $operationsName = implode(', ', $item['operationIds']);
        
                // dd($operationsName);
        
                    // Guardar datos de materialsAlfa en materialName
                    $materials = [];
        
                    //dd($item['materialsAlfa']);
        
                    foreach ($item['materialsAlfa'] as $material) {
        
                        //dd($material);
        
                        $materials[] = $material['materialName'];
        
                        //dd($material);
        
                        // Verificar y guardar la fecha de restricción
                        if ($material['receptionDate'] !== null) {
        
                            $dateTime = DateTime::createFromFormat('d/m/Y H:i:s', $material['receptionDate']);
        
                            $receptionDate = $dateTime->getTimestamp();
                            //dd($receptionDate);
                            if (!isset($maxReceptionDate) || $receptionDate > $maxReceptionDate) {
        
                                $maxReceptionDate = $receptionDate;
                                $restrictionMaterial = $material['materialName'];
                            }
                        }
                    }
        
                    //dd($maxReceptionDate, $restrictionMaterial );
        
                    $restrictionMaterial = isset($restrictionMaterial) ? $restrictionMaterial : null;
        
                    //dd($restrictionMaterial);
        
                    $materialName = implode(', ', $materials);
        
                    //dd($materialName);
        
                    // Guardar la fecha de restricción si existe
                    $restrictionDate = null;
        
                    if (isset($maxReceptionDate)) {
                        $restrictionDate = date('Y-m-d H:i:s', $maxReceptionDate);
                    }
        
                    $theoreticalDuration = 0;
                    $sectionsAlfa = [];
        
                    $iterationSectionAlfa = true;
        
                    if (isset($item['sectionAlfaTime']) && is_array($item['sectionAlfaTime'])) {
        
                        foreach ($item['sectionAlfaTime'] as $key => $value) {

                            if($iterationSectionAlfa == true){

                                $theoreticalDuration += $value;
                                $sectionsAlfa[] = $key;
        
                                $iterationSectionAlfa = false;

                            }else{

                                $theoreticalDuration += $value;
                            }
        
                        
                            
                        }
                    }
        
        
        
                    // me falta obtener el id de MES de las secciones que intervienen en cada fase seguir aqui
        
                    $operationsNameArray =  explode(', ', $operationsName);
                    $materialsNameArray = explode(', ', $materialName);
        
                    //dd($sectionsAlfa, $operationsNameArray);
        
                    $sectionIds = AssetsAsset::whereIn('name', $sectionsAlfa)
                        ->pluck('id')
                        ->toArray();
        
                    $operationsIds = Operation::where('parent_id', $phaseId)
                                    ->pluck('id')
                                    ->toArray();
        
                    $materialsIds  = Material::where('phase_id', $phaseId)
                                    ->pluck('id')
                                    ->toArray();
        
        
                    // aca voy a cambiar cuando dentro de una fase tengo distintas secciones voy a dividir la misma en 2 o mas registros de data
                    // para tener una mejor planificacion despues                 
                                    
        
                    $data[] = [
                        'ofOrderId' => $orderofOrderId,
                        'orderCode' => $orderCode,
                        'phase_id' => $phaseId, // es el id de MES
                        'ofFaseId' => $item["phaseAlfa"], // es el id de alfa
                        'phaseName' => $item["phaseAlfa"], // esta duplicado pero lo voy a dejar igual 
                        'phaseDescription' => $item["phaseDescription"], // crear campo para guardar este dato
                        'section_id' => json_encode($sectionIds), // son los id de MES, // cambiar este campo para que acepte json
                        'sectionName' => json_encode($sectionsAlfa), // es tipo varchar esta bien
                        'operations_id' => json_encode($operationsIds), // son los id de MES
                        'operationsName' => $operationsName, // son los id de alfa
                        'materialsName' => $materialName,
                        'materials_id' => json_encode($materialsIds),
                        'restrictionDate' => $restrictionDate,
                        'restrictionMaterial' => $restrictionMaterial, // crear campo para guardar este dato
                        'theoreticalDuration' => $theoreticalDuration,
        
                    ];




                }else{

                    //dd($item);

                    $maxReceptionDate = null;
                    $restrictionMaterial = null;
        
                    $phaseId = null;

                    $phaseWork = Phase::where('ofFaseId', $item["phaseAlfa"])->first();

                    $phaseId = $phaseWork->id; 

                    // Guardar datos de operationIds en operationsName
                    $operationsName = implode(', ', $item['operationIds']);
        
                    // Guardar datos de materialsAlfa en materialName
                    $materials = [];
        
        
                    foreach ($item['materialsAlfa'] as $material) {
        
                        $materials[] = $material['materialName'];
        
                        // Verificar y guardar la fecha de restricción
                        if ($material['receptionDate'] !== null) {
        
                            $dateTime = DateTime::createFromFormat('d/m/Y H:i:s', $material['receptionDate']);
        
                            $receptionDate = $dateTime->getTimestamp();
                            
                            if (!isset($maxReceptionDate) || $receptionDate > $maxReceptionDate) {
        
                                $maxReceptionDate = $receptionDate;
                                $restrictionMaterial = $material['materialName'];
                            }
                        }
                    }
        
                    //dd($maxReceptionDate, $restrictionMaterial );
        
                    $restrictionMaterial = isset($restrictionMaterial) ? $restrictionMaterial : null;
        
                    //dd($restrictionMaterial);
        
                    $materialName = implode(', ', $materials);
        
                    //dd($materialName);
        
                    // Guardar la fecha de restricción si existe
                    $restrictionDate = null;
        
                    if (isset($maxReceptionDate)) {
                        $restrictionDate = date('Y-m-d H:i:s', $maxReceptionDate);
                    }

                    // me falta obtener el id de MES de las secciones que intervienen en cada fase seguir aqui
        
                    $operationsNameArray =  explode(', ', $operationsName);
                    $materialsNameArray = explode(', ', $materialName);
                                            
                    $operationsIds = Operation::where('parent_id', $phaseId)
                                                    ->pluck('id')
                                                    ->toArray();
            
                        $materialsIds  = Material::where('phase_id', $phaseId)
                                                    ->pluck('id')
                                                    ->toArray();

                    // aca deberia dividir las secciones y los tiempos 

                    $iterationSection = true;

                    // como no se en que operacion particular se encuentran los materiales le voy a asiganr los materiales a 
                    // la primera seccion

                    foreach ($item['sectionAlfaTime'] as $clave => $valor) {

                        $theoreticalDuration = $valor;
                        $sectionsAlfa = [];
                        $sectionsAlfa[] = $clave;

                        $sectionIds = AssetsAsset::where('name',$clave)
                                                ->pluck('id')
                                                ->toArray();

                        if($iterationSection == false){

                            // coloco en null para las siguientes registros 
                            $materialName = null;
                            $materialsIds = null;
                            $restrictionDate= null;
                            $restrictionMaterial = null;

                        }                           
                        

                        $data[] = [
                            'ofOrderId' => $orderofOrderId,
                            'orderCode' => $orderCode,
                            'phase_id' => $phaseId, // es el id de MES
                            'ofFaseId' => $item["phaseAlfa"], // es el id de alfa
                            'phaseName' => $item["phaseAlfa"], // esta duplicado pero lo voy a dejar igual 
                            'phaseDescription' => $item["phaseDescription"], // crear campo para guardar este dato
                            'section_id' => json_encode($sectionIds), // son los id de MES, // cambiar este campo para que acepte json
                            'sectionName' => json_encode($sectionsAlfa), // es tipo varchar esta bien
                            'operations_id' => json_encode($operationsIds), // son los id de MES
                            'operationsName' => $operationsName, // son los id de alfa
                            'materialsName' => $materialName,
                            'materials_id' => $materialsIds,
                            'restrictionDate' => $restrictionDate,
                            'restrictionMaterial' => $restrictionMaterial, // crear campo para guardar este dato
                            'theoreticalDuration' => $theoreticalDuration,
            
                        ];

                        $iterationSection = false;

                    }

                }

            //dd("350");

        
            }

            //dd($data);

            // voy a definir  variable 1 llamda inicio turno, otra llamada duracion turno y otra finalizacion de turno

            // formato carbon

            $shiftStartTimeCarbon = Carbon::createFromTime(6, 0, 0);

            //dd($shiftStartTimeCarbon);

            // duracion del turno de lunes a jueves es 11 horas, los viernes 8

            $shiftHours = 11;
            $shifHouresFriday = 8;

            $shiftEndTimeCarbon = $shiftStartTimeCarbon->copy()->addHours($shiftHours);
            $shiftEndTimeFridayCarbon = $shiftStartTimeCarbon->copy()->addHours($shifHouresFriday);

            //dd($shiftEndTimeCarbon,$shiftEndTimeFridayCarbon);

            // formato string

            $shiftStartTime = $shiftStartTimeCarbon->format('H:i:s');

            $shiftEndTime = $shiftEndTimeCarbon->format('H:i:s');

            //dd($shiftStartTime, $shiftEndTime);

            // dias feriados barcelona españa

            // formato AA/MM/DD
            // agosto vacaciones electricfor

            $holidays = [
                '2024-04-01', '2024-05-01', '2024-06-24','2024-07-01',

                '2024-08-02','2024-08-03','2024-08-04','2024-08-05','2024-08-06','2024-08-07','2024-08-08','2024-08-09','2024-08-10','2024-08-11','2024-08-12',
                '2024-08-13','2024-08-14','2024-08-15','2024-08-16','2024-08-17','2024-08-18','2024-08-19','2024-08-20','2024-08-21','2024-08-22','2024-08-23',
                '2024-08-24','2024-08-25','2024-08-26','2024-08-27','2024-08-28','2024-08-29','2024-08-30',
                
                
                '2024-09-11', '2024-10-12', '2024-11-01', '2024-12-06','2024-12-08','2024-12-23','2024-12-24','2024-12-25','2024-12-26','2024-12-27','2024-12-30',
                '2024-12-31', '2025-01-01'
            ];


            //dd("linea 520");
            //dd($data);

            //ejecuto esta funcion para asegurarme que las posiciones del array esten correctamente ordenadas

            usort($data, function ($a, $b) {
                return $a['phase_id'] - $b['phase_id'];
            });

            // yo deberia analizar antes de asiganr este valor si la orden tiene alguna restricion de fecha, lo voy a hacer
            //antes de analisar cada posicion y guardarlo en una variable para usarla luego en la primera iteracion para determinar
            // la primera fecha de inicio

            //dd($data);

            $restrictionDateOrder  = array_reduce($data, function ($carry, $item) {
                if (isset($item['restrictionDate'])) {
                    $currentDate = strtotime($item['restrictionDate']);
                    if ($carry === null || $currentDate > strtotime($carry['restrictionDate'])) {
                        return $item;
                    }
                }
                return $carry;
            });

            // esta seria la fecha de restriccion mayor osea la fecha de entrega del material mas alejada

            $restrictionDateOrder = isset($restrictionDateOrder) ? $restrictionDateOrder["restrictionDate"] : null;

            //dd($restrictionDateOrder);
            // $restrictionDateOrder = $restrictionDateOrder["restrictionDate"];




            // voy a crear una variable bandera para poder hacer un condicional en la primera iteracion

            $firstIteration = true;

            // voy a crear una variable bandera tambien para guardar la fecha de finalizacion de la seccion
            // para poder asiganarla si es que corresponde como fecha de inicio de la siguiente iteracion

            $nextStartDate = null;

            // aca voy a hacer una busqueda a mi tabla de carga de trabajo para tener este dato extra

            // Obtener la fecha más grande en la columna 'dayCalculation'
            $majorDate = WorkloadSections::max('dayCalculation');

            // Buscar todos los registros que tengan la fecha más grande
            $WorkloadSections = WorkloadSections::where('dayCalculation', $majorDate )->get();


            //continuar mañana realizando los cambios desde aqui
           // dd($WorkloadSections);
            //dd($data);

            //debia tener una productividad determinada por seccion y por hora, si en una seccion por ejemplo tengo una productividad 
            // de dos (2) lo que deberia hacer es por ejemplo cuando cargo una operacion por ejemplo espiralado, voy a tener que verififcar 
            // la finalizacion de la ultima operacion de la of en esa seccion pero como ahora voy a tener la posibilidad de tener solapadas 
            // 2 ofs, deberia seleccionar la que termina antes, voy a agregar a mi tabla de planningSection una columna para guardar
            // un dato llamado productividad , donde guardare los mismos tiempos pero ademas un numero que me indique la productividad por ejemplo 1
            // entonces cuando busque la finalizacion de una seccion buscare la cantidad de registros segun la productividad, por ejemplo
            // cuando sepa que la productividad es 2 buscare los ultimos registros que tengan productividad 1 y 2 y luego puedo compararlos 
            // y empezar cuando termine el menor 

            //crear una variable que tenga la productividad por seccion

            $sectionsProductivity =[

            "99-Almacen MP" => 1,
            "1-Espiralado" => 2,
            "2-Corte tubo" => 2,
            "3-Rellenado Tradicional" => 2,
            "4-Rellenado KOF" => 2,
            "5-Laminado" => 2,
            "7-Recocido Horno" => 2,
            "9-Curvado"=> 2,
            "10-Marcar" => 2,
            "11-Soldadura" => 2,
            "12-Sellado" => 2,
            "13-Montaje" => 2,
            "14-Chorreado" => 2,
            "31-Vulcanizado" => 2,
            "95-Control final" => 2,


            ];
            
            

            //dd($data);

            //dd($sectionProductivity["99-Almacen MP"]);


            for ($i = 0; $i < count($data); $i++) {

                $item = $data[$i];

               // dd($item);

                

                $sectionItem = trim(str_replace(['[', ']', '"'], '', $item["sectionName"]));

                //dd($sectionItem);
               
                $startSection = null;

                $finishSection = null;

                $productivity = $sectionsProductivity[$sectionItem];

               // dd($productivity);

                //$sectionItem = "500-Emilio";

                //voy a crear una variable que me de cuando se libera la seccion segun la carga de trabajo 

                $releaseSections = $WorkloadSections->where('sectionName',$sectionItem)
                                                    ->sortByDesc('id')
                                                    ->pluck('releaseSection')
                                                    ->first();

                // si no encuentra ningun registro de carga de trabajo de la seccion sera igual a null


                // if($sectionItem == "7-Recocido Horno"){

                //     dd($item, $startSection, $finishSection,$releaseSections);
                // }
                

                if (isset($item['sectionName']) || isset($item['phaseDescription'])) {

                    $query = PlanningSection2::query();

                    if (isset($item['sectionName'])) {


                        $sectionName = json_decode($item['sectionName'], true);


                        $query->whereRaw("JSON_CONTAINS(sectionName, '[" . json_encode($sectionName) . "]')");

                        //$query->whereIn('sectionName', $sectionName);

                        //dd($query->get());
                    }



                    // if (isset($item['phaseDescription'])) {
                    //     $query->orWhere('phaseDescription', $item['phaseDescription']);
                    // }



                    // esta seria la fecha de finalizacion de la ultima operacion que hay registrada para la seccion
                    // la cual seria a su vez la fecha de inicio de esta operacion

                    // if($firstIteration != true){

                    //     $endDateSection = $query->latest('id')->value('finish');

                    //     $endDateSectionNueva = $query->max('finish');

                    //     dd("873",$endDateSection,$endDateSectionNueva , $item);

                    // }

                    // cambio esta busqueda porque aqui estoy buscando la de mayor id y deberia buscar la de 
                    // mayor fecha de finalizacion

                    //$endDateSection = $query->latest('id')->value('finish');

                    //nueva formula


                    $endDateSection = $query->max('finish');

                   //dd($endDateSection, "948");

                   // aca voy a tener que cambiar y buscar la fecha final de las ultimas ordenes segun la productividad 
                   // de la seccion, osea si la seccion tiene una productividad de 2 quiere decir que voy a tener que 
                   // hacer de 2 ofs a la vez

                  //segui aqui mañana 


                   // if($sectionItem != "99-Almacen MP"){

                        $maxValues = $query
                                    ->groupBy('productivity')
                                    ->select('productivity', 'finish')
                                    ->orderBy('finish', 'desc') // Ordenar cada grupo por 'finish' descendente
                                    ->get(); // Obtener todos los resultados de cada grupo


                        //dd($maxValues);
            
                        $maxValuesByProductivity = [];            

                        foreach ($maxValues as $group) {

                            //dd($group);

                            $maxValue = $group->finish; // Obtener el primer (máximo) valor de 'finish' dentro de cada grupo
                            $maxValuesByProductivity[$group->productivity] = $maxValue;
                        }   
                        

                        if (!empty($maxValuesByProductivity) && count($maxValuesByProductivity) == 1) {

                            $endDateSection = reset($maxValuesByProductivity);
                           
                        }else{
    
                            $endDateSection = $maxValuesByProductivity;
    
                        }

                        // if($sectionItem != "99-Almacen MP"){

                        //     dd($item, "944", $endDateSection );

                        // }

    
                       

                        

                        

                        //dd($item, "944", $endDateSection );
                  // }


                    
        


                    //dd($item, "944", $endDateSection );
                    //if($endDateSection )
                    // probar la primera planificacion 
                    //$endDateSection = null;

                    //revisar la primera operacion de almacen 

                    //dd($endDateSection);        

                    if ($firstIteration == true) {


                        //dd($item,$endDateSection,$releaseSections);

                        // si es la primera iteracion voy a tener que verificar varias cosas,

                        // si existe una fecha de restriccion por la demora de algun material por ejemplo voy a tener que comparar
                        // si la fecha de resticcion es mayor a la fecha de finalizacion de la ultima operacion de la misma seccion $endDateSection
                        // entonces la fecha de inicio de la primera section de la orden sera igual a la fecha de restricion y si es al revez la fecha de inicio
                        // es igual a $endDateSection - que es la fecha de finalizacion de la ultima orden de la seccion

                        //para probar otras opciones quemo los valores de restictionDate
                        // $restrictionDateOrder = Carbon::create(2023, 6, 21, 10, 0, 0); // Año, Mes, Día, Hora, Minuto, Segundo
                        // $endDateSection = Carbon::create(2023, 8, 21, 10, 0, 0); // Año, Mes, Día, Hora, Minuto, Segundo

                        // Fecha de resticcion de la seccion

                        $restrictionDateFashe = $item["restrictionDate"];

                        // dd($endDateSection, $restrictionDateFashe,$restrictionDateOrder,$shiftStartTimeCarbon);

                        // si la fecha de restriccion de la seccion es mayor a la fecha de finalizacion de la ultima operacion de la seccion 
                        //la fecha de inicio de la seccion debe ser igual a la fecha de restriccion con el horario de inicio del primer turno

                        //dd($restrictionDateFashe);

                        if ($endDateSection === null || ($restrictionDateFashe !== null  && $restrictionDateFashe > $endDateSection)
                            || ($restrictionDateFashe !== null  && $restrictionDateFashe > $releaseSections)) {

                           

                            //este caso seria en la primera operacion que se registra en una seccion 

                            // o cuando la seccion tiene una fecha de resticcion mayor a la fecha de finalizacion de la ultima operacion de la misma seccion

                            // tambien podria ser en el caso de que la fecha de resticcion de la fase sea mayor a la fecha de liberacion de la seccion segun la carfa

                            //$restrictionDateFashe = null;

                            if($restrictionDateFashe !== null){

                                $restrictionDateOrder = Carbon::parse($restrictionDateFashe);

                                $restrictionDateOrderHours = $restrictionDateOrder->setTime($shiftStartTimeCarbon->hour, $shiftStartTimeCarbon->minute, $shiftStartTimeCarbon->second);

                                // en este caso
                                if($restrictionDateOrder < $shiftStartTimeCarbon->addDay()){


                                    $restrictionDateOrderHours = $shiftStartTimeCarbon->addDay()->setTime($shiftStartTimeCarbon->hour, $shiftStartTimeCarbon->minute, $shiftStartTimeCarbon->second);

                                    if ($restrictionDateOrderHours->dayOfWeek === Carbon::SATURDAY ||$restrictionDateOrderHours->dayOfWeek === Carbon::SUNDAY) {
                                        // La fecha es un sábado o domingo
                                        // Calcular la fecha del lunes siguiente
                                        $restrictionDateOrderHours = $restrictionDateOrderHours->copy()->next(Carbon::MONDAY);

                                        $restrictionDateOrderHours->hour = 6;
                                        $restrictionDateOrderHours->minute = 0;
                                        $restrictionDateOrderHours->second = 0;
                                    }

                                }
                                //dd($restrictionDateOrderHours);

                            }else{

                                //dd($shiftStartTimeCarbon, "597");

                                //este seria el caso en que no hay aun registrada ninguna operacion en esta seccion,aca deberia ver cuando empiezo esa operacion 
                                //tendria que ver la carga de trabajo de la fabrica ?? 

                                //dd($item, "945", $releaseSections, $shiftStartTimeCarbon); 

                                if( $releaseSections == null){
                                    $restrictionDateOrderHours = $shiftStartTimeCarbon->addDay();
                                }else{
                                    // en este caso en lugar de empezar el dia de mañana debera empezar cuando se libera la seccion
                                    $restrictionDateOrderHours = Carbon::parse($releaseSections);
                                }
                                                             
                               // dd($restrictionDateOrderHours);


                                //esto no deberia hacerlo mas porque la fecha de liberacion ya lo hace 

                                if ($restrictionDateOrderHours->dayOfWeek === Carbon::SATURDAY ||$restrictionDateOrderHours->dayOfWeek === Carbon::SUNDAY) {
                                    // La fecha es un sábado o domingo
                                    // Calcular la fecha del lunes siguiente
                                    $restrictionDateOrderHours = $restrictionDateOrderHours->copy()->next(Carbon::MONDAY);

                                    $restrictionDateOrderHours->hour = 6;
                                    $restrictionDateOrderHours->minute = 0;
                                    $restrictionDateOrderHours->second = 0;
                                }

                            }

                          

                            // startSection  seria el campo start de la primera seccion de la orden
                            $startSection = $restrictionDateOrderHours;

                            //dd($startSection);

                            // voy a crear una variable que guarde el fin del turno del dia en que se esta ejecutando esta operacion

                            // aca voy a tener 2 casos que analizar porque si el dia en el que estoy es viernes las horas que voy a tener habilitadas para trabajar son menos
                            // debo verificar que el dia en el que estoy es viernes o no

                            // voy a calcular el final del turno del dia en el cual estoy
                             $endDayShift = $this->calculateEndDayShift($startSection, $shiftEndTimeCarbon, $shiftEndTimeFridayCarbon,$item);

                            
                            //dd($startSection, $endDayShift);

                            // ahora comparo la fecha de inicio de la tarea con la fecha de la finalizacion del turno para saber cuantas horas quedan disponibles para trabajar
                            // estas son solo horas redondeadas hacia abajo osea si es 7horas 30 minutos devuelve 7


                            $hoursAvailable = $startSection->diffInHours($endDayShift);

                            $minutesAvailable = $startSection->diffInMinutes($endDayShift) % 60;

                            $hoursAvailable = $hoursAvailable + ($minutesAvailable / 60);

                            $hoursAvailable = round( $hoursAvailable, 3);

                            //dd($hoursAvailable);

                            $theoreticalDuration = $item["theoreticalDuration"];

                            //dd($theoreticalDuration);

                            // $theoreticalDuration = 20.48;

                            // antes de agregar la cantidad de horas para obtener la fecha de finalizacion de la seccion debo verificar si con la cantidad de horas
                            // disponibles en el turno me alcanza para finanlizar la operacion

                            // primer caso si me alcanzan las horas
                            if ($hoursAvailable >  $theoreticalDuration) {

                               // dd("linea 664");
                                // hago directamente el calculo

                                $hoursToAdd = floor($theoreticalDuration);

                                $minutesToAdd = ($theoreticalDuration - $hoursToAdd) * 60;

                                //dd($hoursToAdd,$minutesToAdd );

                                // $finishSection es el campo finish de esta seccion

                                $finishSection = $startSection->copy()->addHours($hoursToAdd)->addMinutes($minutesToAdd);


                                //dd($finishSection, "677");

                            } else {

                                //dd($theoreticalDuration,$hoursAvailable,$startSection ,$shiftStartTimeCarbon );

                                // en este caso no me alcanzan las horas debo hacer otros calculos extras

                                $finishSection = $this->calculateFinishSection(

                                    $theoreticalDuration,
                                    $hoursAvailable,
                                    $startSection,
                                    $shiftStartTimeCarbon,
                                    $holidays
                                );

                                //primero voy a ver cuantas horas del tiempo teorico voy a tener que hacer en el dia o dias siguiente
                                //$theoreticalDuration = 26.95;

                                // dd("linea 607")

                                //voy a hacer una funcion especial para calcular estos tiempos lo siguiente se puede eliminar ya lo hace la funcion 

                                // dd($startSection,$theoreticalDuration, $finishSection);

                            }

                             //dd("1181");

                            // declaro en false la variable para que en la proxima iteracion ya no sea la primera y haga otra logica distinta
                            $firstIteration = false;
                            // guardo en esta variable nextStartDate la finanlizacion de la primera seccion para que si en la proxima iteracion
                            // por ejemplo la fecha de finalizacion de la misma seccion pero de la orden anterios es menor a esta variable
                            // la fecha de inicio sea igual a esta variable que estoy guardando

                            $nextStartDate = $finishSection;

                                // dd($startSection,$finishSection );

                            $startCarbon = Carbon::createFromTimestamp($startSection->timestamp);
                            $finishCarbon = Carbon::createFromTimestamp($finishSection->timestamp);

                            $startFormatted = $startCarbon->toDateTimeString(); // Formato Y-m-d H:i:s
                            $finishFormatted = $finishCarbon->toDateTimeString(); // Formato Y-m-d H:i:s

                            $item['start'] = $startFormatted;
                            $item['finish'] = $finishFormatted;

                            $data[$i] = $item;


                            continue;

                        } else {

                            // sigo en la primera iteracion 

                            

                            //dd("1221", $item);

                            // aca va a ingresar si   $endDateSection es !== null osea ya hay planificadas otras ordenes 
                            // o la fase tiene un restriccion y es menor a la fecha teorica de inicio  $endDateSection o no hay fecha de restriccion
                            // ahora tambien debo considerar la carga de trabajo de la seccion

                            //dd($endDateSection,$releaseSections, $item );

                            $phaseDescription = $item["sectionName"];

                            //dd($phaseDescription);

                            // como aca estoy en la primera iteracion la variable $nextStartDate es null por eso a esta variable para analizar los puntos muertos le voy
                            // a asignar el valor de $endDateSection

                            //si la endDateSection que es la finalizacion de la misma seccion de la ultima orden es mayor a la relaseSection que es cuando 
                            //se libera la seccion segun la carga, como estoy en la primera iteracion la variable nextStartDate sera igual a endDateSection 
                            // esta variable la utilizo para buscar puntos muertos

                            $nextStartDate = null;

                            if($endDateSection >$releaseSections ){
                               $nextStartDate = $endDateSection;
                            }else{
                               $nextStartDate = $releaseSection;
                            }

                           // $nextStartDate = $endDateSection;

                            $startDate = $nextStartDate;

                           // dd($startDate);
                           

                            // nuevo buscador de tiempo muerto

                            $filteredTimeOutSection= $this->calculateTimeoutSections($phaseDescription,$item);

                            //dd($filteredTimeOutSection, "133", $item);
                           
                            //dd( $filteredTimeOutSection);

                            $startSection = null;

                            if($filteredTimeOutSection === []){

                               // dd("1143");

                                // si no tengo ningun tiempo muerto sigo con el mismo valor de start section
                                $startSection = $startDate;

                            }else{

                                //dd($filteredTimeOutSection, "1150", $item,$nextStartDate ,$startDate);

                                // si tengo tiempo muerto debo verificar que los tiempos muertos puedan se usados, osea voy a verificar
                                // si el tiempo de los tiempos muertos me permiten colocar ahi mi operacion

                                //la variable "previous_finish" corresponde a la ultima vez que finalizo la seccion y "next_start" es la ultima vez que empieza
                                // osea que dependiendo de mi fecha inicio debo buscar si tendo un tiempo muerto para empezar antes,

                                //dd("tengo tiempo muerto", $item);

                                

                                $duration = $item["theoreticalDuration"];

                                foreach ($filteredTimeOutSection as $timeOut) {

                                    $previousFinish = $timeOut["previous_finish"];
                                    $nextStart = $timeOut["next_start"];
                                    $durationHours = floatval($timeOut["duration_hours"]);

                                    //dd($durationHours,$duration,$nextStartDate,$timeOut);

                                    // if($durationHours == 30.7333){
                                    //     dd($durationHours,$duration,$nextStartDate,$timeOut);
                                    // }

                                    if ($nextStartDate > $previousFinish &&  $durationHours > $duration) {

                                       
                                        $startSection = $previousFinish;
                                        //dd($startSection, "1233",$duration,$timeOut);
                                        break;
                                    }else{

                                        $startSection = $startDate;
                                    }
                                }

                                //dd($item,$endDateSection,$releaseSections,"1221",$phaseDescription,$startDate,$startSection);


                            }

                                // ahora que tengo la fecha de inicio calculo la fecha de finalizacion de esta seccion

                                //dd($startSection);

                                if (is_string($startSection)) {
                                    $startSection = Carbon::createFromFormat('Y-m-d H:i:s', $startSection);
                                }

                                //dd($item,$endDateSection,$releaseSections,"1221",$phaseDescription,$startDate);

                                //dd($startSection);

                                $endDayShift = $this->calculateEndDayShift($startSection, $shiftEndTimeCarbon, $shiftEndTimeFridayCarbon,$item);

                                // voy a crear una variable que guarde el fin del turno del dia en que se esta ejecutando esta operacion

                                //dd($startSection, $endDayShift);

                                // ahora comparo la fecha de inicio de la tarea con la fecha de la finalizacion del turno para saber cuantas horas quedan disponibles para trabajar
                                // estas son solo horas redondeadas hacia abajo osea si es 7horas 30 minutos devuelve 7

                                $hoursAvailable = $startSection->diffInHours($endDayShift);

                                $minutesAvailable = $startSection->diffInMinutes($endDayShift) % 60;

                                $hoursAvailable = $hoursAvailable + ($minutesAvailable / 60);

                                $hoursAvailable = round( $hoursAvailable, 3);


                                //dd($hoursAvailable);

                                $theoreticalDuration = $item["theoreticalDuration"];

                                    
                                //$theoreticalDuration = 15.48;

                                // antes de agregar la cantidad de horas para obtener la fecha de finalizacion de la seccion debo verificar si con la cantidad de horas
                                 // disponibles en el turno me alcanza para finanlizar la operacion

                                // primer caso si me alcanzan las horas
                                if ($hoursAvailable >  $theoreticalDuration) {

                                        // hago directamente el calculo

                                        $hoursToAdd = floor($theoreticalDuration);

                                        $minutesToAdd = ($theoreticalDuration - $hoursToAdd) * 60;

                                        //dd($hoursToAdd,$minutesToAdd );

                                        // $finishSection es el campo finish de esta seccion

                                        $finishSection = $startSection->copy()->addHours($hoursToAdd)->addMinutes($minutesToAdd);


                                        //dd($finishSection);

                                } else {

                                    
                                        // en este caso no me alcanzan las horas debo hacer otros calculos extras
                                        
                                        $finishSection = $this->calculateFinishSection(

                                            $theoreticalDuration,
                                            $hoursAvailable,
                                            $startSection,
                                            $shiftStartTimeCarbon,
                                            $holidays
                                        );


                                        //todo el codigo siguiente lo reemplazo con la funcion anterior 

                                        //dd($finishSectionNueva,$finishSection );


                                }

                            //dd($startSection, $finishSection, "1554");

                            // aca tengo el caso de que si es la primera iteracion y la fecha de restriccion es menor a la fecha de finalizacion de la
                            // seccion de la ultima orden , aca deberia agregar estos dos valores al item para guardarlos en la base de datos, esto me falta aun

                            // declaro en false la variable para que en la proxima iteracion ya no sea la primera y haga otra logica distinta
                            $firstIteration = false;
                            // guardo en esta variable nextStartDate la finanlizacion de la primera seccion para que si en la proxima iteracion
                            // por ejemplo la fecha de finalizacion de la misma seccion pero de la orden anterios es menor a esta variable
                            // la fecha de inicio sea igual a esta variable que estoy guardando

                            $nextStartDate = $finishSection;



                            $startCarbon = Carbon::createFromTimestamp($startSection->timestamp);
                            $finishCarbon = Carbon::createFromTimestamp($finishSection->timestamp);

                            $startFormatted = $startCarbon->toDateTimeString(); // Formato Y-m-d H:i:s
                            $finishFormatted = $finishCarbon->toDateTimeString(); // Formato Y-m-d H:i:s

                            $item['start'] = $startFormatted;
                            $item['finish'] = $finishFormatted;

                            $data[$i] = $item;

                            // $item['start'] = $startSection->timestamp;
                            // $item['finish'] = $finishSection->timestamp;

                            continue;
                        }



                        // finde la primera iteracion

                    } else {

                        // Aca ahora debo cambiar y verificar apartir de aca la fecha de restriccion de cada fase y compararla con $nextStartDate que
                        // es la fecha en que deberia iniciar esta fase si es que no tiene restriccion, si la fecha de restriccion de la fase es mayor
                        // a la fecha que deberia iniciar esta fase, debo cambiar esta variable por la fecha de restriccion de la fase y si es al reves
                        // debo seguir con esta fecha
                        dd($item, "1445",$endDateSection,$releaseSections,$nextStartDate);

                        //ahora $endDateSection que es la ultima operacion en la seccion, dependiendo de la seccion puede tener mas de una 
                        // fecha, debo elegir pero teniendo en cuenta varios casos
                        //

                       // dd($item, "944", )




                    
                        $restrictionDateFashe = $item["restrictionDate"];

                        if($restrictionDateFashe == null){

                            $restrictionDateFasheCarbon = null;


                        }else{

                            $restrictionDateFasheCarbon = Carbon::parse($restrictionDateFashe);

                            $restrictionDateOrderHours = $restrictionDateFasheCarbon->setTime($shiftStartTimeCarbon->hour, $shiftStartTimeCarbon->minute, $shiftStartTimeCarbon->second);

                            // dd($restrictionDateFasheCarbon);

                            if($restrictionDateFasheCarbon > $nextStartDate ){

                                //dd("1103");

                                $nextStartDate = $restrictionDateOrderHours;

                            }
                            // dd("1108");  
                        }

                       // dd($nextStartDate);

                        // si la fecha de liberacion de la seccion segun la carga de trabajo es mayor a la fecha $nextStarDate que es la fecha
                        // de la finalizacin de la seccion anterior de la orden entonces nextStarDate va a ser igual a la fecha de liberacion de 
                        // la seccion

                        // comento para probar tiempos muertos 
                        //dd($nextStartDate,$releaseSections );

                        if($releaseSections > $nextStartDate){
                           
                            $nextStartDate = $releaseSections;
                           
                        }


                       // dd($nextStartDate);

                       // dd("segunda iteracion", $endDateSection,$nextStartDate, $item["theoreticalDuration"], $restrictionDateFashe,$releaseSections );

                        // ahora si sigo con estas opciones
                        // en esta segunda iteracion puedo tener los siguientes casos

                        // 1- endDateSection que es la finalizacion de la misma seccion pero de la ultima orden sea null o sea menor a
                        // nextStartDate que es la finalizacion de la seccion anterior de la misma orden entonces en este caso  start va a ser
                        // igual a nextStart

                        //-2 que endDateSection sea mayor a nextStart en este caso el satrt de la seccion sera igual a endDateSection porque la
                        // seccion no podra empezar hasta que finalice la operacion anterior

                        


                        //aca ademas de verificar estos parametros debo verificar la carga de trabajo de la seccion 
                        //segun mi carga de trabajo calculada en el comando seguir por aca mañana 

                       //dd($item, $releaseSections,$endDateSection );



                        if (is_string($endDateSection)) {
                            //dd($startSection);
                            $endDateSection = Carbon::createFromFormat('Y-m-d H:i:s', $endDateSection);
                        }

                        if (is_string($nextStartDate)) {
                            //dd($startSection);
                            $nextStartDate = Carbon::createFromFormat('Y-m-d H:i:s', $nextStartDate);
                        }

                        //dd($nextStartDate);


                        if ($endDateSection == null || $endDateSection <  $nextStartDate) {

                            //dd("1123", $item,$endDateSection,$nextStartDate );
                            $startSection = $nextStartDate;

                        }else{

                            //dd("1128");
                             // dd($nextStartDate, $endDateSection, "1690");

                            //  $startSection = $endDateSection;

                            // en este caso la fecha de finalizacion de la seccion de la orden anterior es mayor a mi fecha de imicio teorico, que es
                            // la fecha en que termina la fase o seccion anterior de la orden actual

                            // aca es donde debo chequear si existen tiempos muertos


                            // esta fecha de inicio deberia tener una validacion extra que es buscar un tiempo muerto

                            // obtengo la fase description que es el nombre de la fase y la fecha de inicio segun viene calculando

                            // $phaseDescription = $item["phaseDescription"];

                            $phaseDescription = $item["sectionName"];

                            //dd($item);

                            $startDate = $nextStartDate;


                            //dd($phaseDescription, $startDate);

                            // busco los tiempos muertos de la seccion determinada

                            $filteredTimeOutSection= $this->calculateTimeoutSections($phaseDescription, $item);

                            //dd($filteredTimeOutSection, "1417", $item );

                           
                            if($filteredTimeOutSection === []){

                            

                                // por las dudas voy a buscar el primer registro de tiempo de la seccion para ver si puedo colocar mi tarea

                                // voy a comparar el tiempo entre este primer registro y mi fecha de inicio teorica (fecha de finalizacion de la seccion anterior)
                                // dd($firstSectionTime->start, $item);

                                $firstSectionTime = PlanningSection2::whereNotNull('finish')
                                                                ->whereRaw("JSON_CONTAINS(sectionName, ?)", [$phaseDescription])
                                                                ->orderBy('finish')
                                                                ->first();

                                if(($firstSectionTime->start > $nextStartDate)){

                                    //dd("3495");
                                        // debo ver cuanto tiempo tengo aqui entre estas 2 fechas
                                    $firstSectionTime = $firstSectionTime->start;
                                    $firstSectionTime = Carbon::createFromFormat('Y-m-d H:i:s',  $firstSectionTime);
                                    $diferenciaEnHoras = $firstSectionTime->diffInHours($nextStartDate);

                                    if($diferenciaEnHoras > $item["theoreticalDuration"]){

                                        //dd("3503");
                                        $startSection = $nextStartDate;

                                    }else{

                                        $startSection = $endDateSection;

                                    }
                                    //dd("3507");
                                }else{

                                        // si no tengo ningun tiempo muerto sigo con el mismo valor de start section
                                        $startSection = $endDateSection;

                                }
                                 



                            }else{


                                    //dd("1475");

                                    // si tengo tiempo muerto debo verificar que los tiempos muertos puedan se usados, osea voy a verificar
                                    // si el tiempo de los tiempos muertos me permiten colocar ahi mi operacion

                                    //la variable "previous_finish" corresponde a la ultima vez que finalizo la seccion y "next_start" es la ultima vez que empieza
                                    // osea que dependiendo de mi fecha inicio debo buscar si tendo un tiempo muerto para empezar antes,

                                    //dd("tengo tiempo muerto",  $phaseDescription,$filteredTimeOutSection, $nextStartDate);



                                    $duration = $item["theoreticalDuration"];

                                    // $firstSectionTime = PlanningSection2::whereNotNull('finish')
                                    //                     ->where('phaseDescription', '=', $phaseDescription)
                                    //                     ->orderBy('finish')
                                    //                     ->first();


                                    // voy a comparar el tiempo entre este primer registro y mi fecha de inicio teorica (fecha de finalizacion de la seccion anterior)
                                    // dd($firstSectionTime->start, $item);

                                    $firstSectionTime = PlanningSection2::whereNotNull('finish')
                                                                            ->whereRaw("JSON_CONTAINS(sectionName, ?)", [$phaseDescription])
                                                                            ->orderBy('finish')
                                                                            ->first();

                                    //dd($firstSectionTime->toArray(),$filteredTimeOutSection,$nextStartDate) ;                                        

                                    if(($firstSectionTime->start > $nextStartDate)){

                                            //dd("3495");
                                            // debo ver cuanto tiempo tengo aqui entre estas 2 fechas
                                            $firstSectionTime = $firstSectionTime->start;
                                            $firstSectionTime = Carbon::createFromFormat('Y-m-d H:i:s',  $firstSectionTime);
                                            $diferenciaEnHoras = $firstSectionTime->diffInHours($nextStartDate);

                                            if($diferenciaEnHoras > $item["theoreticalDuration"]){

                                                //dd("3503");
                                                $startSection = $nextStartDate;

                                            }else{

                                                // aca reviso los tiempos muertos y los valido

                                                foreach ($filteredTimeOutSection as $timeOut) {

                                                    $previousFinish = $timeOut["previous_finish"];
                                                    $nextStart = $timeOut["next_start"];
                                                    $durationHours = floatval($timeOut["duration_hours"]);

                                                    if ( (($nextStartDate > $previousFinish && $nextStartDate < $nextStart) && $durationHours > $duration)) {

                                                        $startSection = $nextStartDate;
                                                    // dd($startSection, "3648");
                                                        break;

                                                    }elseif((($nextStartDate < $previousFinish && $nextStartDate < $nextStart) && $durationHours > $duration)){

                                                        $startSection = $previousFinish;
                                                        //dd($startSection, "3474");
                                                        break;


                                                    }
                                                    else{

                                                        $startSection = $endDateSection;

                                                    }

                                                    //$startSection = $endDateSection;

                                                }
                                                //dd("3507");
                                            }


                                    }else{

                                      // dd("1557",$filteredTimeOutSection,$duration,$item,$nextStartDate );

                                            foreach ($filteredTimeOutSection as $timeOut) {

                                                $previousFinish = $timeOut["previous_finish"];
                                                $nextStart = $timeOut["next_start"];
                                                $durationHours = floatval($timeOut["duration_hours"]);

                                                if ( (($nextStartDate > $previousFinish && $nextStartDate < $nextStart) && $durationHours > $duration)) {

                                                    $startSection = $nextStartDate;
                                                   //dd($startSection, "3648");
                                                    break;

                                                }elseif((($nextStartDate < $previousFinish && $nextStartDate < $nextStart) && $durationHours > $duration)){

                                                    $startSection = $previousFinish;
                                                    //dd($startSection, "3474");
                                                    break;


                                                }
                                                else{
                                                   //dd("1580",$endDateSection );

                                                    $startSection = $endDateSection;

                                                    //dd($startSection );

                                                }

                                                //$startSection = $endDateSection;

                                            }




                                    }




                            }



                            // $startSection = $endDateSection;
                        }

                        //dd($startSection);

                        if (is_string($startSection)) {
                            //dd($startSection);
                            $startSection = Carbon::createFromFormat('Y-m-d H:i:s', $startSection);
                        }

                        // ahora que tengo la fecha de inicio calculo la fecha de finalizacion de esta seccion

                        //dd($startSection);
                        try {
                            $dayOfWeek = $startSection->dayOfWeek;
                        } catch (\Exception $e) {
                            // Manejo del error o depuración
                            dd($e->getMessage(),$e->getTrace(), $startSection, $item , $filteredTimeOutSection, $nextStartDate, $endDateSection );
                        }

                        // voy a crear una variable que guarde el fin del turno del dia en que se esta ejecutando esta operacion}

                        $endDayShift = $this->calculateEndDayShift($startSection, $shiftEndTimeCarbon, $shiftEndTimeFridayCarbon,$item);

                        //dd($startSection, $endDayShift);

                        // ahora comparo la fecha de inicio de la tarea con la fecha de la finalizacion del turno para saber cuantas horas quedan disponibles para trabajar
                        // estas son solo horas redondeadas hacia abajo osea si es 7horas 30 minutos devuelve 7

                        $hoursAvailable = $startSection->diffInHours($endDayShift);

                        $minutesAvailable = $startSection->diffInMinutes($endDayShift) % 60;


                        $hoursAvailable = $hoursAvailable + ($minutesAvailable / 60);

                        $hoursAvailable = round( $hoursAvailable, 3);


                        $theoreticalDuration = $item["theoreticalDuration"];

                         //dd($item);

                    

                        //dd("1977", $item);

                        //$theoreticalDuration = 5.48;

                        // antes de agregar la cantidad de horas para obtener la fecha de finalizacion de la seccion debo verificar si con la cantidad de horas
                        // disponibles en el turno me alcanza para finanlizar la operacion

                        // primer caso si me alcanzan las horas
                        if ($hoursAvailable >  $theoreticalDuration) {

                            // hago directamente el calculo

                            $hoursToAdd = floor($theoreticalDuration);

                            $minutesToAdd = ($theoreticalDuration - $hoursToAdd) * 60;

                        
                            //dd($hoursToAdd,$minutesToAdd );

                            // $finishSection es el campo finish de esta seccion

                            $finishSection = $startSection->copy()->addHours($hoursToAdd)->addMinutes($minutesToAdd);


                            //dd($finishSection);

                        } else {

                            // en este caso no me alcanzan las horas debo hacer otros calculos extras

                            $finishSection = $this->calculateFinishSection(

                                $theoreticalDuration,
                                $hoursAvailable,
                                $startSection,
                                $shiftStartTimeCarbon,
                                $holidays
                            );

                           


                        }

                        //dd($startSection, $finishSection);


                        // guardo en esta variable nextStartDate la finanlizacion de la primera seccion para que si en la proxima iteracion
                        // por ejemplo la fecha de finalizacion de la misma seccion pero de la orden anterios es menor a esta variable
                        // la fecha de inicio sea igual a esta variable que estoy guardando

                        $nextStartDate = $finishSection;



                        $startCarbon = Carbon::createFromTimestamp($startSection->timestamp);
                        $finishCarbon = Carbon::createFromTimestamp($finishSection->timestamp);

                        $startFormatted = $startCarbon->toDateTimeString(); // Formato Y-m-d H:i:s
                        $finishFormatted = $finishCarbon->toDateTimeString(); // Formato Y-m-d H:i:s

                        $item['start'] = $startFormatted;
                        $item['finish'] = $finishFormatted;

                        $data[$i] = $item;

                        // $item['start'] = $startSection->timestamp;
                        // $item['finish'] = $finishSection->timestamp;

                        //dd($startSection,$finishSection );

                        continue;
                    }



                    // Continuar con la iteración
                }
            }

           //dd("linea 926");

            // continuar aca mañana y agregar a la base de datos de order planning el inicio de la orden


            dd($data);

            // busco la fecha de inicio de la orden
            $firstItem = reset($data);
            $startDate = $firstItem['start'];

            //debo sacar de data la fecha de finalizacion que es la fecha finish de la ultima seccion
            $lastItem = end($data); // Obtener el último elemento del array
            $planningDate = $lastItem['finish'];
            $carbonPlanningDate = Carbon::parse($planningDate);

            $planningDate = Carbon::createFromTimestamp($carbonPlanningDate->timestamp);
            $planningDateFormatted = $planningDate->toDateTimeString();


            // antes de insertar los datos voy a obtener los datos para insertar en la tabla de orderPlanning


            $orderPlanning = Order::where('id', $orderId)
                                    ->get()
                                    ->toArray();

            //dd($orderPlanning);
            //debo hacer el calculo de la demora y guardarlo en una variable seria la diferencia entre lo esperado y lo planificado

            $deliveryDate = $orderPlanning[0]["deliveryDate"];

            $carbonDeliveryDate  = Carbon::parse($deliveryDate);

            $deliveryDate = Carbon::createFromTimestamp($carbonDeliveryDate->timestamp);


            $deliveryDateFormatted = $deliveryDate->toDateTimeString();

            // calculo la demora en cantidad de dias

            $lateDelivery = $carbonPlanningDate->diff($carbonDeliveryDate);

            // tipo de demora

            $typeLate = $lateDelivery->invert;

            // dias de diferencia

            $lateDelivery = $lateDelivery->days;

        // dd($lateDelivery->days, $typeLate );

            if($typeLate == 0){

                $lateDelivery = -$lateDelivery ;
            }

        // dd( $lateDelivery );
        //dd($orderPlanning[0]["description"]);     


            $orderPlanningData = [
                'ofOrderId' => $orderofOrderId,
                'client' => $orderPlanning[0]["description"],
                'code' => $orderCode,
                'deliveryDate' => $deliveryDateFormatted, // fecha teorica de finalizacion
                'planningDate' => $planningDateFormatted,
                'lateDelivery' => $lateDelivery,
                'startDate'  => $startDate
            ];

            //dd($orderPlanningData);

            DB::beginTransaction();

            try {

                //PlannigSection::insert($data);

                foreach ($data as $item) {
                    $planningSection = new PlanningSection2();
                    $planningSection->fill($item);
                    $planningSection->save();
                }

                $orderPlanning = new PlanningOrders2();
                $orderPlanning->fill($orderPlanningData);
                $orderPlanning->save();


                DB::commit();

                return response()->json(['success' => true, 'data' => 'Informacion Guardada'], 200);

            } catch (\Exception $e) {

            // dd("hubo un error", $e);

                DB::rollback();

                // Manejo del error
            }

        }

    public function calculateEndDayShift($startSection, $shiftEndTimeCarbon, $shiftEndTimeFridayCarbon,$item)
    {
       // dd($startSection, $shiftEndTimeCarbon, $shiftEndTimeFridayCarbon,$item);

        if ($startSection->dayOfWeek === Carbon::FRIDAY) {

            return $startSection->copy()->setDate(
                $startSection->year,
                $startSection->month,
                $startSection->day
            )->setTime(
                $shiftEndTimeFridayCarbon->hour,
                $shiftEndTimeFridayCarbon->minute,
                $shiftEndTimeFridayCarbon->second
            );
        } else {

            return $startSection->copy()->setDate(
                $startSection->year,
                $startSection->month,
                $startSection->day
            )->setTime(
                $shiftEndTimeCarbon->hour,
                $shiftEndTimeCarbon->minute,
                $shiftEndTimeCarbon->second
            );
        }
    }


    protected function calculateFinishSection($theoreticalDuration, $hoursAvailable, $startSection, $shiftStartTimeCarbon,$holidays)

    {
        $hoursNextDays = $theoreticalDuration - $hoursAvailable;
        $nextDayWeek = $startSection->copy()->addDay();

        for ($h = 0; $hoursNextDays > 0; $h++) {

            while ($this->isHolidayOrWeekend($nextDayWeek, $holidays)) {
                $nextDayWeek->addDay();
            }

            if ($nextDayWeek->dayOfWeek == Carbon::FRIDAY) {
                $hoursNextDays = $hoursNextDays - 8;
            } else {
                $hoursNextDays = $hoursNextDays - 11;
            }
        
            if ($hoursNextDays > 0) {
                $nextDayWeek = $nextDayWeek->addDay();
            } else {
                $hoursNextDays = $nextDayWeek->dayOfWeek == Carbon::FRIDAY ? 8 - abs($hoursNextDays) : 11 - abs($hoursNextDays);
                $hoursNext = floor($hoursNextDays);
                $minutesNext = ($hoursNextDays - $hoursNext) * 60;
                $finishSection = $nextDayWeek->copy()->setTime(
                    $shiftStartTimeCarbon->hour + $hoursNext,
                    $shiftStartTimeCarbon->minute + $minutesNext,
                    $shiftStartTimeCarbon->second
                );
                break;
            }
        }

        return $finishSection;
    }

    protected function isHolidayOrWeekend($date, $holidays)
    {
        return $date->isWeekend() || in_array($date->format('Y-m-d'), $holidays);
    }

    private function calculateTimeoutSections($phaseDescription, $item)
    {


        $sectionsTimes = PlanningSection2::whereNotNull('finish')
            ->whereRaw("JSON_CONTAINS(sectionName, ?)", [$phaseDescription])
            ->orderBy('finish')
            ->get();

      


       // dd($sectionsTimes->toArray());    

      

        //dd($item,$item["phase_id"] );

        $timeOutSection = [];
        $previousFinish = null;

        if ($sectionsTimes->count() > 0) {
            // Inicializa $previousFinish con la fecha y hora de inicio del primer registro
            $previousFinish = Carbon::parse($sectionsTimes->first()->start);
        }

        //dd($previousFinish);

        // foreach ($sectionsTimes as $section) {

        //    // dd($section,$previousFinish,"1906");

        //     if ($previousFinish !== null) {

        //         $previousFinishDateTime = Carbon::parse($previousFinish);
        //         $sectionStartDateTime = Carbon::parse($section->start);

        //         $durationHours = $previousFinishDateTime->diffInMinutes($sectionStartDateTime) / 60;

        //         $nextStart = $section->start;

        //         $timeOutSection[] = [
        //             'previous_finish' => $previousFinish,
        //             'next_start' => $nextStart,
        //             'duration_hours' => number_format($durationHours, 4),
        //         ];
        //     }

        //     //dd($timeOutSection);

        //     $previousFinish = $section->finish;
        // }

        // PROBAR CODIGO NUEVO 

        foreach ($sectionsTimes as $section) {


            if ($previousFinish !== null) {
                $previousFinishDateTime = Carbon::parse($previousFinish);
                $sectionStartDateTime = Carbon::parse($section->start);
        
                // Solo calcula el tiempo de espera si la fecha y hora de inicio de la sección actual es posterior
                // a la fecha y hora de finalización de la sección anterior
                if ($sectionStartDateTime > $previousFinishDateTime) {
                    $durationHours = $previousFinishDateTime->diffInMinutes($sectionStartDateTime) / 60;
        
                    $timeOutSection[] = [
                        'previous_finish' => $previousFinish,
                        'next_start' => $section->start,
                        'duration_hours' => number_format($durationHours, 4),
                    ];
                }
            }
        
            $previousFinish = $section->finish;
        }

        //dd($timeOutSection);

        if (!empty($timeOutSection) && $timeOutSection[0]['duration_hours'] == 0) {
            array_shift($timeOutSection);
        }

        $filteredTimeOutSection = array_filter($timeOutSection, function ($item) {
            return floatval($item['duration_hours']) > 0;
        });

        // if($item["phase_id"] != 5551 ){

        //     dd($item,$sectionsTimes->toArray(),$filteredTimeOutSection );




        // }   
    

        for ($i = 0; $i < count($filteredTimeOutSection); $i++) {

                //dd($filteredTimeOutSection[$i]);

                // Convertir las fechas de inicio y finalización a objetos Carbon
                $previousFinishDateTime = Carbon::parse($filteredTimeOutSection[$i]['previous_finish']);
                $nextStartDateTime = Carbon::parse($filteredTimeOutSection[$i]['next_start']);
                
                // Obtener el día de la semana del próximo inicio
                $nextStartDayOfWeek = $nextStartDateTime->dayOfWeek;

                 // Verificar si las fechas son del mismo día
                if ($previousFinishDateTime->isSameDay($nextStartDateTime)) {
                    // No hacer nada si las fechas son del mismo día
                    continue;

                } else {

                    // Establecer la fecha de inicio igual a la fecha de finalización
                    $nextStartDateTime = Carbon::parse($filteredTimeOutSection[$i]['previous_finish']);

                     // Ajustar la hora a las 15:00 si es viernes
                    if ($previousFinishDateTime->dayOfWeek === Carbon::FRIDAY) {

                        $nextStartDateTime->setTime(15, 0, 0);  

                    } else {

                        $nextStartDateTime->setTime(17, 0, 0);

                    }

                    $durationHours = $previousFinishDateTime->diffInHours($nextStartDateTime);
                  
                   
                     $filteredTimeOutSection[$i]['next_start'] = $nextStartDateTime->toDateTimeString();
                     $filteredTimeOutSection[$i]['duration_hours'] = number_format($durationHours, 4);

                    // dd($filteredTimeOutSection[$i], "2060");
                }


        }

        return $filteredTimeOutSection;
    }


    public function orderToPlanning(Request $request)
	{


        //dd($request["order"]);
        //dd("endpoint para mostrar ordenes a planificar");

        // Valida si el parámetro "paginate" existe en la solicitud
        $paginate = $request->has("paginate") && is_numeric($request->input("paginate")) && $request->input("paginate") > 0 ? $request->input("paginate") : 10;

        // Valida si el parámetro "page" existe en la solicitud
        $currentPage = $request->has("page") && is_numeric($request->input("page")) && $request->input("page") > 0 ? $request->input("page") : 1;

        // Valida si el parámetro "by" existe en la solicitud

        $orderByParam = $request->has("by") && in_array($request->input("by"), ["created_at", "other_column"]) ? $request->input("by") : "deliveryDate";

        // Valida si el parámetro "order" existe en la solicitud
        $orderByFlow = $request->has("order") && in_array($request->input("order"), ["asc", "desc"]) ? $request->input("order") : "asc";

        $to = ($currentPage * $paginate);

        $search = $request->search;

        $queryFilter =(isset($request['query']) && $request['query'] != "") ? $request['query'] : NULL;


       // dd($search, $queryFilter);

        // ids de ordenes planificadas

        $ordersPlannedIds = PlanningOrders2::pluck('ofOrderId')->unique()->toarray();

       

        //id de ordenes completadas

        $OrdersComplete = Order::where('order_status_id', 6)->pluck('ofOrderId')->unique()->toarray();       

        //dd($OrdersComplete);
        //$ordersToPlanned = Order::whereNotIn('ofOrderId', $ordersPlannedIds);

        $ordersToPlanned = Order::whereNotIn('ofOrderId', $ordersPlannedIds)
                                  ->whereNotIn('ofOrderId', $OrdersComplete);
                                  

            if ($search) {

                     $ordersToPlanned->where(function ($query) use ($search) {
                          $query->where('description', 'LIKE', '%' . $search . '%')
                                ->orWhere('code', 'LIKE', '%' . $search . '%')
                                ->orWhere('deliveryDate', 'LIKE', '%' . $search . '%')
                                ->orWhere('articleDescription', 'LIKE', '%' . $search . '%');

                    });
                }

            //dd($queryFilter);

            if (!empty($queryFilter)) {

                $filterJson = json_decode($queryFilter[0], true);

                if (isset($filterJson['AND']) && is_array($filterJson['AND'])) {

                    $column = $filterJson['AND'][0];

                    if($column == "barcode"){

                        $column = "code";
                    }

                    $operator = $filterJson['AND'][1];
                    $value1 = $filterJson['AND'][2];
                    $value2 = $filterJson['AND'][3];

                    //dd($column, $operator,$value1,$value2 );

                    if ($operator === 'BETWEEN') {
                        $ordersToPlanned->whereBetween($column, [$value1, $value2]);
                    }elseif ($operator === '=') {
                        $ordersToPlanned->where($column, '=', $value1);
                    } elseif ($operator === '>') {
                        $ordersToPlanned->where($column, '>', $value1);
                    } elseif ($operator === '<') {
                        $ordersToPlanned->where($column, '<', $value1);
                    } elseif ($operator === '>=') {
                        $ordersToPlanned->where($column, '>=', $value1);
                    } elseif ($operator === '<=') {
                        $ordersToPlanned->where($column, '<=', $value1);
                    } elseif ($operator === 'LIKE'){
                        $ordersToPlanned->where($column, 'LIKE', "%$value1%");
                    }

                }


            }

        $count = $ordersToPlanned->count();

        //dd($count);

        $ordersToPlanned = $ordersToPlanned->orderBy($orderByParam, $orderByFlow)
                                            ->skip(($currentPage - 1) * $paginate)
                                            ->take($paginate)
                                            ->get();



        $ordersToPlanned = $ordersToPlanned->map(function ($ordersToPlanned) {
            $ordersToPlanned['barcode'] = $ordersToPlanned['code'];
            unset($ordersToPlanned['code']);
            return $ordersToPlanned;
        });

        //dd($ordersToPlanned);

       // $count = Order::whereNotIn('id', $ordersPlannedIds)->count();


        $current_page = $currentPage ?? 1;
        $from = ($currentPage *  $paginate) -  $paginate + 1;
        $last_page = ceil($count /  $paginate);
        $per_page =  $paginate;
        $links = [
            [
                'url' => $current_page == 1 ? null : $request->fullUrlWithoutQuery(['page']) . '&page=' . ($current_page - 1),
                'label' => '« Previous',
                'active' => false
            ],
            [
                'url' => $request->fullUrlWithoutQuery(['page']) . '&page=' . $current_page,
                'label' => $current_page,
                'active' => true
            ],
            [
                'url' => $current_page == $last_page ? null : $request->fullUrlWithoutQuery(['page']) . '&page=' . ($current_page + 1),
                'label' => 'Next »',
                'active' => false
            ],
        ];




        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $currentPage,
                'data' => $ordersToPlanned,
                'from' => $from,
                'last_page' => $last_page,
                'per_page' => $paginate,
                'to' => $to,
                'total' => $count,
                'links' => $links
            ]
        ], 200);

	}

    public function plannedOrders(Request $request)
	{

        //dd("controlador ordenes  planificadas");


        $paginate = $request->get("paginate");
        $currentPage = $request->get("page");
        $orderByParam = $request->get("by")?? 'planningDate';
        $orderByFlow = $request->get("order") ?? "asc";
        $search = $request->get("search");
        $to = ($currentPage * $paginate);

        //dd($orderByParam,$orderByFlow);


        // parametros para hacer una busqueda por un rango de fechas
        $dateFrom = $request->get("date_from");
        $dateTo = $request->get("date_to");
        // parametro para filtrar por el tipo de fecha osea deliveryDate o planningDate
        $dateField = $request->get("date_field");

        $queryFilter = (isset($request['query']) && $request['query'] != "") ? $request['query'] : NULL;


       // dd($paginate, $currentPage, $orderByParam, $orderByFlow, $to);

        $query= PlanningOrders2::query();



        // Aplicar búsqueda si se proporciona un término de búsqueda
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('client', 'LIKE', '%' . $search . '%')
                  ->orWhere('orderName', 'LIKE', '%' . $search . '%')
                  ->orWhere('deliveryDate', 'LIKE', '%' . $search . '%')
                  ->orWhere('planningDate', 'LIKE', '%' . $search . '%')
                  ->orWhere('lateDelivery', 'LIKE', '%' . $search . '%');
            });
        }

        //dd($queryFilter);

        if (!empty($queryFilter)) {

            $filterJson = json_decode($queryFilter[0], true);

            if (isset($filterJson['AND']) && is_array($filterJson['AND'])) {

                $column = $filterJson['AND'][0];
                $operator = $filterJson['AND'][1];
                $value1 = $filterJson['AND'][2];
                $value2 = $filterJson['AND'][3];

                //dd($column, $operator,$value1,$value2 );

                if ($operator === 'BETWEEN') {
                    $query->whereBetween($column, [$value1, $value2]);
                }elseif ($operator === '=') {
                    $query->where($column, '=', $value1);
                } elseif ($operator === '>') {
                    $query->where($column, '>', $value1);
                } elseif ($operator === '<') {
                    $query->where($column, '<', $value1);
                } elseif ($operator === '>=') {
                    $query->where($column, '>=', $value1);
                } elseif ($operator === '<=') {
                    $query->where($column, '<=', $value1);
                } elseif ($operator === 'LIKE'){
                    $query->where($column, 'LIKE', "%$value1%");
                }

            }


            //dd($filterJson);

        }


        //deliveryDate

       // $orderByParam = "planningDate";
       // $orderByFlow = "desc";


        $query->orderBy($orderByParam, $orderByFlow);

        $ordersPlanned = $query->paginate($paginate);

        // $ordersPlannedPrueba = $query->get();

        // dd($ordersPlannedPrueba->toArray(),  $ordersPlanned->toArraY());

        $count = $ordersPlanned->count();

        //dd( $ordersPlanned );



        return response()->json([
            'success' => true,
            'data' =>  $ordersPlanned,

        ], 200);



    }

    public function infoOrderToPlanned($id)
    {

         //dd("controlador informacion de la orden A planificar", $id);

        $order = Order::where('id', $id)->first();

        $ofCode = $order->code; 

        //dd($order->code);

        $phasesOrder = Phase::where('parent_id', $id)->get();

        function convertHours($decimalHours) {

            $hours = floor($decimalHours);
            $minutes = round(($decimalHours - $hours) * 60);

            return  "$hours horas $minutes minutos";
            }


        $phasesData = [];

       // dd($phasesOrder->toArray());

        foreach ($phasesOrder as $phase) {

            //dd($phase);

            $code = $phase->code;

            $theoreticalTotalTime = $phase->theoricalTotalTime;

            $theoreticalDurationPhase = convertHours($phase->theoricalTotalTime);

            $materials = Material::where('phase_id', $phase->id)    
                                   ->get();

            //dd($materials->toArray());
            
            $restrictionMaterialPhase = [];

            if ($materials->isNotEmpty()) {

                foreach ($materials as $material) {

                    
                    
                    if($material->quantityAvailable < $material->quantityPlanned &&  $material->receptionDate == null ){

                        //dd($material);

                        $restrictionMaterialPhase[] = [
                            "materialId" => $material->id,
                            "valeMaterialId" => $material->valeMaterial,
                            "materialName" => $material->description,
                            "restrictionMaterialCode" => $material->code,
                            "restrictionDate" => $material->receptionDate,
                            "quantityAvailable" => $material->quantityAvailable,
                            "quantityReception" => $material->quantityReception,
                            "quantityPlanned" => $material->quantityPlanned,
                            
                        ];

                       // dd($material, $restrictionMaterialPhase);

                    }else if($material->quantityAvailable < $material->quantityPlanned &&  $material->receptionDate !== null ){

                       // dd($material, "2511");
 
                         $restrictionMaterialPhase[] = [
                             "materialId" => $material->id,
                             "valeMaterialId" => $material->valeMaterial,
                             "materialName" => $material->description,
                             "restrictionMaterialCode" => $material->code,
                             "restrictionDate" => $material->receptionDate,
                             "quantityAvailable" => $material->quantityAvailable,
                             "quantityReception" => $material->quantityReception,
                             "quantityPlanned" => $material->quantityPlanned,
                             
                         ];
 
                         //dd($material, $restrictionMaterialPhase);
 
                     }else if($material->receptionDate !== null){

                        

                        $restrictionMaterialPhase[] = [
                            "materialId" => $material->id,
                            "valeMaterialId" => $material->valeMaterial,
                            "materialName" => $material->description,
                            "restrictionMaterialCode" => $material->code,
                            "restrictionDate" => $material->receptionDate,
                            "quantityAvailable" => $material->quantityAvailable,
                            "quantityReception" => $material->quantityReception,
                            "quantityPlanned" => $material->quantityPlanned,
                            
                        ];

                    }else{

                       // $restrictionMaterialPhase = [];


                    }
    
    

                }

            }   

            
                    //  $restrictionMaterialPhase[] = [
                    //         "restrictionMaterial" => $materials[0]["name"],
                    //         "restrictionDate" => $materials[0]["receptionDate"],

                    //     ];
            // }else{

            //     $restrictionMaterialPhase = [];

            // }

            $phasesData[] = [
                'descriptionPhase' => $code,
                'theoreticalTotalTimePhase' => $theoreticalDurationPhase,
                'materialRestrictionPhase' => $restrictionMaterialPhase
            ];
        }

         //dd($phasesData);

         return response()->json([
            'success' => true,
            'data' =>[
                'phasesData' => $phasesData,
                'oFCode' => $ofCode 
            ]

            ], 200);

    }


    public function infoPlannedOrders($id)
    {

       // dd("controlador informacion de la orden planificada", $id);



        // podria hacer una busqueda en mi tabla de planificacion de secciones donde busque si la orden tiene alguna restriccion de materiales
        // si las tiene buscar las fechas de restriccion y los nombres de los materiales asi como la seccion a la cual pertenece

        $restrictionOrder = PlanningSection2::where('ofOrderId', $id)
                                            ->whereNotNull('restrictionDate')
                                            ->orderBy('restrictionDate', 'desc')
                                            ->get();
       // dd()

        $restrictionOrders = $restrictionOrder ? $restrictionOrder->toArray() : null;

        $restrictionMaterialData = [];

        if( $restrictionOrder == null){
           // $restrictionMaterialData = [];
        }else{

            foreach ($restrictionOrders as $restrictionOrder) {

                $restrictionMaterialData[] = [
                    "restrictionDate" => $restrictionOrder["restrictionDate"],
                    "phaseDescription" => $restrictionOrder["phaseDescription"],
                    "restrictionMaterial" => $restrictionOrder["restrictionMaterial"]
                ];
            }

        }

        function convertHours($decimalHours) {

            $hours = floor($decimalHours);
            $minutes = round(($decimalHours - $hours) * 60);

            return  "$hours horas $minutes minutos";
        }

        $startDate = PlanningSection2::where('ofOrderId', $id)
                                    ->orderBy('start', 'asc')
                                    ->first();

        // en este campo tengo la fecha de inicio real de la produccion de la orden

        $startDate = $startDate ? $startDate->toArray()["start"] : null;


        $totalTheoreticalDuration = PlanningSection2::where('ofOrderId', $id)
                                                    ->sum('theoreticalDuration');

        $totalTheoreticalDuration = convertHours($totalTheoreticalDuration);


        $infoPhases = PlanningSection2::where('ofOrderId', $id)->get()->toArray();

        $infoPhasesTimes = [];

        foreach ($infoPhases as $item) {

            $theoreticalDurationPhase = convertHours($item["theoreticalDuration"]);

            $infoPhasesTimes[] = [
                "phaseDescription" => $item["phaseDescription"],
                "start" => $item["start"],
                "finish" => $item["finish"],
                "theoreticalDuration" => $theoreticalDurationPhase,
            ];
        }


        $data = [
            "restrictionMaterialData" => $restrictionMaterialData,
            "startDate" => $startDate,
            "totalTheoreticalDuration" => $totalTheoreticalDuration,
            "infoPhasesTimes" => $infoPhasesTimes

        ];

        return response()->json([
            'success' => true,
            'data' => $data,

        ], 200);



    }

    public function plannedOrdersSections(Request $request)
    {
       
           // endpoint para mostrar la planificacion por secciones

           //dd($request->date_from);

           $dateFrom = $request->date_from;
           $dateTo = $request->date_to;

           $holidays = [
            '2024-04-01', '2024-05-01', '2024-06-24','2024-07-01',

            '2024-08-02','2024-08-03','2024-08-04','2024-08-05','2024-08-06','2024-08-07','2024-08-08','2024-08-09','2024-08-10','2024-08-11','2024-08-12',
            '2024-08-13','2024-08-14','2024-08-15','2024-08-16','2024-08-17','2024-08-18','2024-08-19','2024-08-20','2024-08-21','2024-08-22','2024-08-23',
            '2024-08-24','2024-08-25','2024-08-26','2024-08-27','2024-08-28','2024-08-29','2024-08-30',
            
            
            '2024-09-11', '2024-10-12', '2024-11-01', '2024-12-06','2024-12-08','2024-12-23','2024-12-24','2024-12-25','2024-12-26','2024-12-27','2024-12-30',
            '2024-12-31', '2025-01-01'
            ];

            $shiftStartTimeCarbon = Carbon::createFromTime(6, 0, 0);

            // duracion del turno de lunes a jueves es 11 horas, los viernes 8

            $shiftHours = 11;
            $shifHouresFriday = 8;

            $shiftEndTimeCarbon = $shiftStartTimeCarbon->copy()->addHours($shiftHours);
            $shiftEndTimeFridayCarbon = $shiftStartTimeCarbon->copy()->addHours($shifHouresFriday);



             $planningSections = PlanningSection2::whereDate('start', '>=', $dateFrom)
                                                ->whereDate('start', '<=', $dateTo)
                                                ->get();

           //dd($planningSections->toArray()) ;  
      
           
           $groupedSections = [];

            // Iteramos sobre la colección
            foreach ($planningSections as $section) {

                //dd($section);

                if($section->sectionName !== "[]"){

                   // dd($section);

                    // Obtenemos el valor de "sectionName" para identificar la seccion
                    $sectionName = $section->sectionName;
                    
                    // Verificamos si ya existe una entrada en $groupedSections para este "sectionName"

                    if (!array_key_exists($sectionName, $groupedSections)) {
                        // Si no existe, creamos una nueva entrada con un array vacío
                        $groupedSections[$sectionName] = [];
                    }
                    
                    // Agregamos el registro actual al array correspondiente en $groupedSections
                    $groupedSections[$sectionName][] = $section;
                    
                }
            
                

               
            }


            // return response()->json([
            //     'success' => true,
            //     'data' => $groupedSections,
    
            //     ], 200);

          //dd($groupedSections);


           // seguir con este codigo 

           
            foreach ($groupedSections as $sectionName => $sectionData) {

                foreach ($sectionData as $entry) {

                   // dd($entry->sectionName);

                   // if($sectionData[])

                    $startDateTime = Carbon::parse($entry->start);
                    $finishDateTime = Carbon::parse($entry->finish);
            
                    // Compara el componente de fecha de start y finish
                    if (!$startDateTime->isSameDay($finishDateTime)) {

                        //dd($entry->ofFaseId);

                        // if($entry->ofFaseId == 1090916){

                        //     dd($entry  );
                        // }

                     

                        if ($startDateTime->isFriday()) {
                            // Si es viernes, establece la hora de finalización en $shiftEndTimeFridayCarbon
                            $entry->finish = $startDateTime->copy()
                                            ->setTimeFromTimeString('15:00:00')
                                            ->toDateTimeString();
                        } else {
                            // Si es cualquier otro día, establece la hora de finalización en $shiftEndTimeCarbon
                           // $entry->finish = $startDateTime->copy()->setTimeFromTimeString($shiftEndTimeCarbon)->toDateTimeString();

                           $entry->finish = $startDateTime->copy()
                                            ->setTimeFromTimeString('17:00:00')
                                            ->toDateTimeString();
                        }

                        // $date->copy()->setTimeFromTimeString('15:00:00') :
                        // $date->copy()->setTimeFromTimeString('17:00:00');

                       


                          // Calcular la duración real del trabajo
                          //$realDuration = $startDateTime->floatDiffInHours($entry->finish);

                          $newFinishHoures = Carbon::parse($entry->finish);

                          // esta diferencia serian la shoras utilizadas no las faltanes
                          $difference = $startDateTime->diff($newFinishHoures);

                          //dd($newFinishHoures,$difference ,$startDateTime );

                       

                          
                          // Obtener las horas y minutos de la diferencia
                          $hours = $difference->h;
                          $minutes = $difference->i;

                          // estas serian las horas trabajadas
                          $hoursWorked= $hours + round(($minutes / 60),2);

                          $remainingDuration = $entry->theoreticalDuration - $hoursWorked;

                          //dd($remainingDuration);

                        //   if($entry->ofFaseId == 1090916){

                        //     dd($remainingDuration );
                        // }

                       
                        while ($remainingDuration > 0) {  

                            //if ($remainingDuration > 0) {

                                $nextWorkDay = $startDateTime->copy()->addDay();
                            
                                //dd($nextWorkDay);

                                 // Buscar el siguiente día hábil

                                while ($nextWorkDay->isWeekend() || in_array($nextWorkDay->toDateString(), $holidays)) {
                                    $nextWorkDay->addDay(); // Sumar un día
                                }

                            

                                // Crear una copia de $entry

                                $newEntry = clone $entry;

                                //dd($newEntry);
                                //dd($nextWorkDay );

                                // Establecer la hora de inicio
                                $nextWorkDay->hour = 6; // Establecer la hora a las 6 AM

                                // Si necesitas también establecer los minutos y segundos:
                                $nextWorkDay->minute = 0;
                                $nextWorkDay->second = 0;

                                // Ahora puedes asignar la fecha y hora a la nueva entrada
                                $newEntry->start = $nextWorkDay->toDateTimeString();

                                // Calcular las horas restantes disponibles en el próximo día laborable
                                $maxHours = $nextWorkDay->isFriday() ? 8 : 11;

                                //dd($remainingDuration);

                                // Asegurarse de que la duración no exceda el límite máximo de horas para el día
                                $totalHoursForDay = min($remainingDuration, $maxHours);

                                if( $maxHours < $remainingDuration ){

                                    $hours = floor($maxHours);
                                    $minutes = 0;

                                }else{

                                    $hours = floor($remainingDuration);
                                    $minutes = ($remainingDuration - $hours) * 60;

                                } 

                                $startCarbon = Carbon::parse($newEntry->start);

                                // Agregar las horas y minutos a la fecha
                                $newFinishDateTime = $startCarbon->copy()->addHours($hours)->addMinutes($minutes);

                                // Establecer la fecha de finalización
                                $newEntry->finish = $newFinishDateTime->toDateTimeString();

                                $remainingDuration -= $totalHoursForDay;

                                $index = array_search($entry, $sectionData);

                                array_splice($sectionData, $index + 1, 0, [$newEntry]);

                                // Actualiza $sectionData en $groupedSections
                                $groupedSections[$sectionName] = $sectionData;

                               // dd($index );
                            
                            //}

                        }
                            
                  



                        //dd($entry);

                    } 
                 
                }
              
            }


           

            return response()->json([
                'success' => true,
                'data' => $groupedSections,
    
                ], 200);
    




    }

    // voy a hacer un endpoint para calcular la carga de trabajo luego lo transformare en un comando, creo que podria generar una tabla
    // para guardar la carga de trabajo por seccion y que a la noche se actualice o se cree un nuevo registro para guardar 
    // cuanto tiempo le falta a cada seccion para terminar las ordenes que estan empezadas, luego podria replanificar las ordenes
    // teniendo en cuenta estos tiempos, deberia ver para el momento de la replanificacion como hago esto si agrego este tiempo a las ordenes
    // o tomo la fecha de finalizacion de cada seccion y vuelvo a planificar todas las ordenes siguientes.

    // primero voy a hacer un endpoint que me diga la carga de trabajo de cada seccion para saber cuando inicio la primera orden a planificar
    // luego planificare a partir de los tiempos de la primera orden planificada

    //

    public function workloadSections(Request $request)
    {

        //dd("endpoint para ver las cargas de trabajos por secciones");

        //primero necesito buscar las ordenes que esten iniciadas  y buscar aquellas operaciones que esten en Fin de jornada

         
        //puedo probar en lugar de buscar las ordenes primero buscar las operaciones que estan en fin de jornada

       //Operation 

       $operations = Operation::with('parent.parent')->where('order_status_id', 8)->get();

       //dd($operations);

       $totalOperations = $operations->count();

       $operationsPaused = Operation::with('parent.parent')->where('order_status_id', 7)->count();

        

       $operacionesData = [];

       foreach ($operations as $operation) {

        $operacionData = [

            'operationId' => $operation->id,
            'operationOfId' => $operation->ofOperationId,
            "faseId" => $operation->parent->id,
            "faseOfId" => $operation->parent->ofFaseId,
            "orderId" => $operation->parent->parent->id,
            "orderOfId" => $operation->parent->parent->ofOrderId,
               
        ];
    
        // Agrega el array de datos de la operación al array principal
        $operacionesData[] = $operacionData;

        }

       // dd($operacionesData);

        $data = [];

        

        foreach ($operacionesData as $operacion) { 

             // aca debo cambiar y iterar por todas las fases de la orden la logica posterior esta correcta 

            //dd($operacion);

            $phasesOrder = Phase::with('operations')
                                ->where('parent_id', $operacion["orderId"])
                                ->where('id', '>=', $operacion["faseId"])
                                ->get();

            $fisrtIteration = true;

            foreach ($phasesOrder as $phaseOrder) {

                if($fisrtIteration == true){

                    //dd("2856");

                    //primero voy a buscar la fase donde se encuentra la operacion fin de jornada
  
                  //$phase = Phase::with('operations')->where('id', $operacion["faseId"])->first();

                  //dd( $phase,$phaseOrder);
                  // busco todas las operaciones de la fase
  
                  $operationsPhase = $phaseOrder->operations;

                  
  
                    foreach ($operationsPhase as $operacionPhase) {
  
                      //dd($operacionPhase);
                      // solo voy a tener encuenta aquellas operaciones cuyo id sea mayor al id de la operacion que esta en fin de jornada
  
                        if($operacionPhase->id >= $operacion["operationId"] ){
  
                          $sectionOperation =  $operacionPhase->resource->name;
                          $sectionTotalTime = $operacionPhase->theoricalTotalTime;
  
                          $existingIndex = null;
  
                          foreach ($data as $index => $existingData) {
  
                              if ($existingData['sectionName'] == $sectionOperation) {
                                  $existingIndex = $index;
                                  break;
                              }
                          }
  
                          // Si existe un array con 'sectionName' igual a $sectionOperation, suma el tiempo teórico total
  
                          if ($existingIndex !== null) {
  
                              $data[$existingIndex]['theoricalTotalTime'] += $sectionTotalTime;
  
                          } else {
                             
                              $data[] = [
                                  'sectionName' => $sectionOperation,
                                  'theoricalTotalTime' => $sectionTotalTime,
                              ];
                          }
  
                          
                            //dd($sectionOperation,$operacionPhase);
  
                                   
  
                        }               
                  
                    }
  
                     $fisrtIteration = false;
  
                     // dd($fisrtIteration);
  
  
                }else{

                    //dd("2919");
                    // este seria el caso de las fases siguientes a la fase de la operacion en fin de jornada

                    //dd($phaseOrder );

                    $operationsPhase = $phaseOrder->operations;

                  
  
                    foreach ($operationsPhase as $operacionPhase) {
  
                      //dd($operacionPhase);
                      // solo voy a tener encuenta aquellas operaciones cuyo id sea mayor al id de la operacion que esta en fin de jornada
  
                          $sectionOperation =  $operacionPhase->resource->name;
                          $sectionTotalTime = $operacionPhase->theoricalTotalTime;
  
                          $existingIndex = null;
  
                          foreach ($data as $index => $existingData) {
  
                              if ($existingData['sectionName'] == $sectionOperation) {
                                  $existingIndex = $index;
                                  break;
                              }
                          }
  
                          // Si existe un array con 'sectionName' igual a $sectionOperation, suma el tiempo teórico total
  
                          if ($existingIndex !== null) {
  
                              $data[$existingIndex]['theoricalTotalTime'] += $sectionTotalTime;
  
                          } else {
                             
                              $data[] = [
                                  'sectionName' => $sectionOperation,
                                  'theoricalTotalTime' => $sectionTotalTime,
                              ];
                          }
  
                          
                            //dd($sectionOperation,$operacionPhase);
                   
                  
                    }
               
                }    
            }

     

        }

       // dd($data);


        $spiraledTotalTime = 0;
        $tuboTotalTime = 0;
        $rellenarTotalTime = 0;
        $laminarTotalTime = 0;
        $recTotalTime = 0;

        foreach ($data as $key =>$item) {

            if ($item['sectionName'] === '101-TMP Estirar a medida') {
                // Sumar el tiempo a '1-Espiralado'
                $spiraledTotalTime += $item['theoricalTotalTime'];
                unset($data[$key]);
            }elseif ($item['sectionName'] === '102-TM cortar tubo') {

                $tuboTotalTime += $item['theoricalTotalTime'];
                unset($data[$key]);
            }elseif ($item['sectionName'] === '103-TM rellenado') {

                $rellenarTotalTime += $item['theoricalTotalTime'];
                unset($data[$key]);
            }elseif ($item['sectionName'] === '105-TM laminado') {

                $laminarTotalTime += $item['theoricalTotalTime'];
                unset($data[$key]);
            } elseif ($item['sectionName'] === '107-TM recocido horno') {

                $recTotalTime += $item['theoricalTotalTime'];
                unset($data[$key]);
            }
        }

        // Actualizar los valores en el array
        foreach ($data as &$item) {
            if ($item['sectionName'] === '1-Espiralado') {
                $item['theoricalTotalTime'] += $spiraledTotalTime;
            }elseif ($item['sectionName'] === '2-Corte tubo') {
                $item['theoricalTotalTime'] += $tuboTotalTime;
            }elseif ($item['sectionName'] === '4-Rellenado KOF') {
                $item['theoricalTotalTime'] +=  $rellenarTotalTime;
            }elseif ($item['sectionName'] === '5-Laminado') {
                $item['theoricalTotalTime'] += $laminarTotalTime ;
            } elseif ($item['sectionName'] === '7-Recocido Horno') {
                $item['theoricalTotalTime'] += $recTotalTime;
            }
        }

      //  dd($data);

       

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $data,
                'totalOperations' => $totalOperations 
            ]

            ], 200);



    }


    public function orderReplanning(Request $request)
    {

       // dd($request->ids);

         //ver como me llega desde el front
         $priorityOrdersIds = $request->ids;
         // lo transformo en un array asociativo para luego poder iterar sobre el
         $priorityOrdersIds= json_decode($priorityOrdersIds);

        // dd($priorityOrdersIds);

        // parece que funciona correctamente ver el lunes como sigo, deberia realizar lo siguiente

        // hacer un endpoint para mostrar la planificacion actual de la empresa junto con los datos de la simulacion 
        // para poder comparar los dias y ver como cambio la simulacion
        // hacer endpoint para que si se aprueba la simulacion se traspacen los datos de las nuevas tablas de simulacion
        // para la tabla de planificacion original


         // Despacha el job con los parámetros - para que se ejecute en segundo plano 
        //  ReplanningProduction::dispatch($priorityOrdersIds);

        //  return response()->json([
        //     'success' => true,
        //     'data' => [
        //         'data' => "Simulacion de Planififcacion en Proceso",
        //     ]

        //     ], 200);




         //Sync - para probar las logica - voy a usar este por ahora luego lo cambio
         ReplanningProduction::dispatchSync($priorityOrdersIds);

         return response()->json([
            'success' => true,
            'data' => [
                'data' => "Simulacion de Planififcacion en Proceso",
            ]

            ], 200);






        


    }


}
