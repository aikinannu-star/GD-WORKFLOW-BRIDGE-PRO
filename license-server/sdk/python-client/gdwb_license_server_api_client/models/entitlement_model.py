from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset

from ..models.plan import Plan
from typing import cast






T = TypeVar("T", bound="EntitlementModel")



@_attrs_define
class EntitlementModel:
    """ 
        Attributes:
            plan (Plan):
            features (list[str]):
     """

    plan: Plan
    features: list[str]
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        plan = self.plan.value

        features = self.features




        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
            "plan": plan,
            "features": features,
        })

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        d = dict(src_dict)
        plan = Plan(d.pop("plan"))




        features = cast(list[str], d.pop("features"))


        entitlement_model = cls(
            plan=plan,
            features=features,
        )


        entitlement_model.additional_properties = d
        return entitlement_model

    @property
    def additional_keys(self) -> list[str]:
        return list(self.additional_properties.keys())

    def __getitem__(self, key: str) -> Any:
        return self.additional_properties[key]

    def __setitem__(self, key: str, value: Any) -> None:
        self.additional_properties[key] = value

    def __delitem__(self, key: str) -> None:
        del self.additional_properties[key]

    def __contains__(self, key: str) -> bool:
        return key in self.additional_properties
