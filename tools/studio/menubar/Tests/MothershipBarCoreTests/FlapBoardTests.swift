import MothershipBarCore
import Testing

@Test func flapTextIsCutWithAnEllipsisAndPadded() {
    #expect(String(flapCells("Online", count: 8)) == "ONLINE  ")
    #expect(String(flapCells("vectorization queue down", count: 14)) == "VECTORIZATION…")
}

@Test func flapRowKeepsItsColumnsAndClockStaysRight() {
    let row = flapRowCells(name: "ArtCraft", value: "13 to update", nameCells: 10, cells: 25, trailing: ">")
    #expect(row.count == 25)
    #expect(String(row) == "ARTCRAFT   13 TO UPDATE >")
    #expect(String(flapHeadlineCells("Restarting", trailing: "00:12", cells: 16)) == "RESTARTING 00:12")
}

@Test func unchangedCellsStayStillAndBlankCellsNeverFlip() {
    let plan = flapPlan(from: [Array("AB ")], to: [Array("AC ")])
    #expect(plan.cells[0][0] == nil)
    #expect(plan.cells[0][1] != nil)
    #expect(plan.cells[0][2] == nil)
}

@Test func theWholeBoardArrivesInsideOneSecondAndEndsOnItsText() {
    let target = [flapHeadlineCells("Needs attention", trailing: nil, cells: 16)]
        + (0..<7).map { _ in Array(repeating: Character("W"), count: 25) }
    let blank = target.map { Array(repeating: flapBlank, count: $0.count) }
    let plan = flapPlan(from: blank, to: target)
    #expect(plan.duration < 1)
    #expect(plan.glyphs(at: plan.duration) == target)
}

@Test func aCellWaitsItsTurnThenSquashesOncePerStep() {
    let cell = FlapCell(glyphs: [" ", "A", "B", "C"], delay: 0.1)
    #expect(cell.frame(at: 0.05) == FlapFrame(glyph: " ", scale: 1))
    #expect(cell.frame(at: 0.1 + FlapTiming.step / 2).glyph == "A")
    #expect(abs(cell.frame(at: 0.1 + FlapTiming.step / 2).scale - FlapTiming.squash) < 0.001)
    #expect(cell.frame(at: cell.end) == FlapFrame(glyph: "C", scale: 1))
}
