<?php

namespace App\Http\Controllers;

use App\Type;
use App\Enums\RespondentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Response as SurveyResponse;
use App\PublicTransportUserResponse;
use App\SurveyData;
use App\PublicTransportOperatorResponse;
use App\PublicTransportRegulatorResponse;

class ResponseController extends Controller
{
    public function getRespondentType()
    {
        $respondent_types = RespondentType::toArray();
        $respondent_type = request("respondent_type");

        if (empty($respondent_type) || !in_array(request("respondent_type"), $respondent_types)) {
            $respondent_type = collect($respondent_types)->first();
        };

        return $respondent_type;
    }

    public function index()
    {
        $responses = SurveyResponse::query()
            ->select(
                'responses.respondent_name', 'responses.respondent_sex',
                'responses.respondent_age', 'responses.respondent_address',
                'responses.extra_data_type'
            )
            ->paginate(20);

        return view('response.index', compact('responses'));
    }

    public function create()
    {
        $respondent_type = $this->getRespondentType();

        $types = Type::query()
            ->select('id', 'name')
            ->with([
                'criteria:id,name,type_id',
                'criteria.sub_criteria:id,name,criterion_id',
                'criteria.sub_criteria.alternatives:id,name,sub_criterion_id',
            ])
            ->orderBy("id")
            ->where('types.respondent_type', $respondent_type)
            ->get();

        switch ($respondent_type) {
            case RespondentType::public_transport_user(): {
                return view('response.public_transport_user.create', compact('types', 'respondent_type'));
                break;
            }
            case RespondentType::public_transport_operator_investor(): {
                return view('response.public_transport_operator.create', compact('types', 'respondent_type'));
                break;
            }
            case RespondentType::public_transport_regulator(): {
                return view('response.public_transport_regulator.create', compact('types', 'respondent_type'));
                break;
            }
        }
    }

    public function store()
    {
        $respondent_type = $this->getRespondentType();
        
        $validator = Validator::make(request()->all(), []);

        switch($respondent_type) {
            case RespondentType::public_transport_user(): {
                
                $validator->addRules([
                    "respondent_occupation" => "required",
                    "respondent_monthly_revenue" => "required|numeric|bail|gte:1",
                    "is_public_transport_user" => "required",
                ]);

                $validator->sometimes(["public_transport_usage_purpose", "desired_public_transport_type"], "required|string", function ($input) {
                    return $input->is_public_transport_user == 1;
                });

                $validator->sometimes("public_transport_usage_duration", "required|string", function ($input) {
                    return $input->is_public_transport_user == 1;
                });

                $validator->sometimes([
                    "public_transport_disuse_reason",
                ], ["required"], function ($input) {
                    return $input->is_public_transport_user == 0;
                });

                break;
            }
            case RespondentType::public_transport_operator_investor(): {
                $validator->addRules([
                    "respondent_occupation" => "required|string",
                    "is_transport_company_owner" => "required|boolean",
                    "position_in_company" => "required|string",
                    "duration_in_business" => "required|numeric|bail|gte:1",
                    "company_monthly_revenue" => "required|numeric|bail|gte:1",
                    "difficulties_in_operation" => "required|string",
                    "wish_and_recommendations" => "nullable|string",
                    "desired_types_of_public_transport" => "nullable|string",
                ]);
                break;
            }
            case RespondentType::public_transport_regulator(): {
                $validator->addRules([
                    "department" => "required|string",
                    "position" => "required|string",
                    "department_authority_level" => "required|string",
                    "difficulties_in_public_trans_impl" => "required|string",
                    "wishes_recommendations_for_impl" => "required|string",
                    "recommended_public_trans_type" => "required|string",
                ]);
                break;
            }
        } 

        $validator->addRules([
            "respondent_name" => "required",
            "respondent_sex" => "required",
            "respondent_age" => "required|numeric|bail|lte:120",
            "respondent_address" => "required",
            "survey_data" => "required",
            "survey_data.*.rating" => "required|string",
            "survey_data.*.type_id" => "required",
            "survey_data.*.criterion_id" => "required",
            "survey_data.*.sub_criterion_id" => "required",
            "survey_data.*.alternative_id" => "nullable"
        ]);

        $data = collect($validator->validate());
        
        DB::transaction(function() use ($data, $respondent_type) {
            $survey_response = new SurveyResponse($data->only([
                "respondent_name",
                "respondent_sex",
                "respondent_age",
                "respondent_address",
            ])->toArray());

            $extra_data = null;
            switch ($respondent_type) {
                case RespondentType::public_transport_user(): {
                    $extra_data = PublicTransportUserResponse::create($data->only([
                        "respondent_occupation",
                        "respondent_monthly_revenue",
                        "is_public_transport_user",
                        "public_transport_usage_duration",
                        "public_transport_usage_purpose",
                        "desired_public_transport_type",
                        "public_transport_disuse_reason",
                    ])->toArray());
                    break;
                }
                case RespondentType::public_transport_operator_investor(): {
                    $extra_data = PublicTransportOperatorResponse::create($data->only([
                        "respondent_occupation",
                        "is_transport_company_owner",
                        "position_in_company",
                        "duration_in_business",
                        "company_monthly_revenue",
                        "difficulties_in_operation",
                        "wish_and_recommendations",
                        "desired_types_of_public_transport",
                    ])->toArray());
                    break;
                }
                case RespondentType::public_transport_regulator(): {
                    $extra_data = PublicTransportRegulatorResponse::create($data->only([
                        "department",
                        "position",
                        "department_authority_level",
                        "difficulties_in_public_trans_impl",
                        "wishes_recommendations_for_impl",
                        "recommended_public_trans_type",
                    ])->toArray());
                    break;
                }
            }

            $survey_response
                ->extra_data()
                ->associate($extra_data);
                    
            $survey_response->save();

            foreach ($data->get("survey_data") as $survey_datum) {
                $survey_datum["response_id"] = $survey_response->id;
                SurveyData::create($survey_datum);
            }
        });

        return back()
            ->with("message_state", "success")
            ->with("message", __("messages.survey_finished"));
    }
}
